"""HTTP + real database Queue smoke test, using an isolated workspace-local SQLite DB.

Run with Python 3 from any directory; requires php with the installed GD module.
No real user accounts, reviewed medical catalog, or production DB are touched.
"""
import json
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import time
import urllib.error
import urllib.request
import uuid
import zlib


def main():
    backend = Path(__file__).resolve().parents[1]
    root = backend / "tmp" / f"babi-http-{uuid.uuid4().hex}"
    root.mkdir(parents=True)
    try:
        db = root / "smoke.sqlite"
        db.touch()
        env = {**os.environ, "APP_ENV": "testing", "APP_DEBUG": "false", "DB_CONNECTION": "sqlite", "DB_DATABASE": str(db), "CACHE_STORE": "array", "QUEUE_CONNECTION": "database", "MAIL_MAILER": "array", "BABI_VACCINE_CATALOG_VERSION": "HTTP-TEST-ONLY", "BABI_PRIVATE_MEDIA_ROOT": str(root / "private-storage")}
        flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0

        php_executable = os.environ.get("BABI_PHP", shutil.which("php") or "php")

        def php(*args, cwd=backend):
            result = subprocess.run([php_executable, "-d", "extension=gd", *args], cwd=cwd, env=env, capture_output=True, text=True, creationflags=flags)
            if result.returncode:
                raise RuntimeError(result.stdout + result.stderr)

        php("artisan", "migrate", "--force", "--no-interaction")
        seed = root / "seed.php"
        seed.write_text("<?php\nrequire " + repr(str(backend / "vendor/autoload.php").replace("\\", "/")) + ";\n$app = require " + repr(str(backend / "bootstrap/app.php").replace("\\", "/")) + ";\n$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\nif (!app()->environment('testing')) { exit(2); }\nIlluminate\\Support\\Facades\\DB::table('vaccine_templates')->insert(['vaccine_code'=>'HTTP-TEST','name'=>'Synthetic HTTP test only','dose_number'=>1,'disease'=>'TEST ONLY','program'=>'expanded','schedule_version'=>'HTTP-TEST-ONLY','timing_rule'=>'clinical','source_url'=>'https://example.invalid/test-only','created_at'=>now(),'updated_at'=>now()]);\n", encoding="utf-8")
        php(str(seed))
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        base = f"http://127.0.0.1:{port}/api/"
        token = None

        def call(method, path, data=None, headers=None, raw=False, expected=200):
            body = data if isinstance(data, bytes) else json.dumps(data).encode() if data is not None else None
            request_headers = {"Accept": "application/json", "Content-Type": "application/json", **({"Authorization": "Bearer " + token} if token else {}), **(headers or {})}
            request = urllib.request.Request(base + path, data=body, headers=request_headers, method=method)
            try:
                response = urllib.request.urlopen(request, timeout=20)
            except urllib.error.HTTPError as error:
                response = error
            content = response.read()
            assert response.status == expected, (path, response.status, content[:500])
            return content if raw else json.loads(content)

        log = (root / "server.log").open("w", encoding="utf-8")
        server = subprocess.Popen([php_executable, "-d", "extension=gd", "-d", "upload_max_filesize=50M", "-d", "post_max_size=512M", "-S", f"127.0.0.1:{port}", str(backend / "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php")], cwd=backend / "public", env=env, stdout=log, stderr=log, creationflags=flags)
        memory_id = None
        try:
            server_ready = False
            for _ in range(50):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=1):
                        pass
                    server_ready = True
                    break
                except OSError:
                    if server.poll() is not None:
                        break
                    time.sleep(0.1)
            if not server_ready:
                log.flush()
                server_output = (root / "server.log").read_text(encoding="utf-8", errors="replace")
                raise RuntimeError(f"PHP server did not become ready (exit={server.poll()}):\n{server_output}")
            origin = "http://127.0.0.1:5173"
            preflight = urllib.request.Request(
                base + "memories",
                headers={
                    "Origin": origin,
                    "Access-Control-Request-Method": "POST",
                    "Access-Control-Request-Headers": "authorization,content-type,x-operation-key",
                },
                method="OPTIONS",
            )
            with urllib.request.urlopen(preflight, timeout=10) as response:
                allowed_origin = response.headers.get("Access-Control-Allow-Origin")
                allowed_headers = response.headers.get("Access-Control-Allow-Headers", "").lower()
                assert response.status == 204, response.status
                assert allowed_origin == origin, allowed_origin
                for required_header in ("authorization", "content-type", "x-operation-key"):
                    assert required_header in allowed_headers, allowed_headers
            account = call("POST", "auth/register", {"name": "HTTP test", "email": "smoke@example.invalid", "password": "test-password-123", "password_confirmation": "test-password-123"}, expected=201)
            token = account["data"]["token"]
            version = call("GET", "app-config")["data"]["policy_version"]
            child = call("POST", "child-profiles", {"name": "HTTP test child", "date_of_birth": "2026-10-01", "gender": "female", "representative_confirmed": True, "storage_consent": True, "family_sharing_acknowledged": True, "policy_version": version}, headers={"X-Operation-Key": str(uuid.uuid4())}, expected=201)["data"]
            child_id = child["id"]
            call("POST", "health-records", {"child_profile_id": child_id, "measured_at": "2026-10-02", "height_cm": 50, "weight_kg": 3.5, "measurement_method": "lying", "expected_data_revision": child["data_revision"]}, expected=201)

            def chunk(kind, payload):
                return struct.pack(">I", len(payload)) + kind + payload + struct.pack(">I", zlib.crc32(kind + payload) & 0xFFFFFFFF)

            png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 2, 1, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(b"\0\xff\0\0\0\xff\0")) + chunk(b"IEND", b"")
            boundary = "babi-" + uuid.uuid4().hex
            fields = {"child_profile_id": str(child_id), "caption": "Actual HTTP upload", "memory_date": "2026-10-02"}
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
            webm = bytes.fromhex("1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102")
            mp4 = bytes.fromhex("000000186674797069736f6d0000020069736f6d69736f32")
            for filename, mime, content in (("test.png", "image/png", png), ("recording.mp4", "video/mp4", mp4), ("recording.webm", "video/webm", webm)):
                parts.append(
                    f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()
                    + content
                    + b"\r\n"
                )
            parts.append(f"--{boundary}--\r\n".encode())
            key = str(uuid.uuid4())
            upload_headers = {"Content-Type": "multipart/form-data; boundary=" + boundary, "X-Operation-Key": key}
            memory = call("POST", "memories", b"".join(parts), headers=upload_headers, expected=201)["data"]
            memory_id = memory["id"]
            assert memory["status"] == "uploading"
            assert call("POST", "memories", b"".join(parts), headers=upload_headers, expected=201)["data"]["id"] == memory_id
            php("artisan", "queue:work", "--stop-when-empty", "--tries=3")
            memory = call("GET", f"memories/{memory_id}")["data"]
            assert memory["status"] == "published", memory
            assert len(memory["media"]) == 3, memory["media"]
            downloaded = [call("GET", "media-files/" + str(item["id"]), raw=True) for item in memory["media"]]
            assert any(content.startswith(b"\x89PNG") for content in downloaded)
            assert mp4 in downloaded, "The private MP4 recording was changed or not returned"
            assert webm in downloaded, "The private WebM recording was changed or not returned"
            revision = call("GET", f"child-profiles/{child_id}")["data"]["data_revision"]
            call("DELETE", f"memories/{memory_id}", {"confirmed": True, "expected_data_revision": revision}, expected=202)
            memory_id = None
            php("artisan", "babi:reconcile-media")
            call("POST", "auth/logout")
            call("GET", "auth/me", expected=401)
            print("PASS: browser CORS preflight; real HTTP register/auth, atomic child creation, health write, multipart PNG+MP4+WebM idempotency, database queue publication, authenticated private image/video download, deletion/cleanup, token revocation.")
        finally:
            # If an assertion failed, remove only the synthetic test's storage paths.
            cleanup = root / "cleanup.php"
            cleanup.write_text("<?php\nrequire " + repr(str(backend / "vendor/autoload.php").replace("\\", "/")) + ";\n$app = require " + repr(str(backend / "bootstrap/app.php").replace("\\", "/")) + ";\n$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\nforeach (Illuminate\\Support\\Facades\\DB::table('memories')->get() as $m) { foreach(json_decode($m->source_manifest,true) as $s) Illuminate\\Support\\Facades\\Storage::disk('local')->delete($s['path']); }\nforeach (Illuminate\\Support\\Facades\\DB::table('media_files')->get() as $f) Illuminate\\Support\\Facades\\Storage::disk('local')->delete($f->file_path);\n", encoding="utf-8")
            try:
                php(str(cleanup))
            finally:
                server.terminate()
                server.wait(timeout=10)
                log.close()
    finally:
        shutil.rmtree(root, ignore_errors=False)


if __name__ == "__main__":
    main()
