"""Run Flutter Web camera capture through a fake Chrome camera and isolated Laravel SQLite."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_for_port(port, process, log_path):
    for _ in range(100):
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=1):
                return
        except OSError:
            if process.poll() is not None:
                break
            time.sleep(0.1)
    output = log_path.read_text(encoding="utf-8", errors="replace") if log_path.exists() else ""
    raise RuntimeError(f"Local server on port {port} did not start:\n{output}")


def main():
    root_project = Path(__file__).resolve().parents[2]
    backend = root_project / "backend"
    frontend = root_project / "frontend"
    root = backend / "tmp" / f"babi-browser-{uuid.uuid4().hex}"
    root.mkdir(parents=True)
    api_port = int(os.environ.get("BABI_BROWSER_API_PORT", "49317"))
    web_port = free_port()
    while web_port == api_port:
        web_port = free_port()
    origin = f"http://127.0.0.1:{web_port}"
    api_url = f"http://127.0.0.1:{api_port}/api"
    php_executable = os.environ.get("BABI_PHP", shutil.which("php") or "php")
    python_executable = os.environ.get("BABI_PYTHON", sys.executable)
    flutter_executable = os.environ.get("BABI_FLUTTER", r"C:\src\flutter\flutter\bin\flutter.bat")
    node_executable = os.environ.get("BABI_NODE", shutil.which("node") or "node")
    node_modules = os.environ.get(
        "BABI_NODE_MODULES",
        r"C:\Users\ASUS\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules",
    )
    env = {
        **os.environ,
        "APP_ENV": "testing",
        "APP_DEBUG": "false",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": str(root / "browser-smoke.sqlite"),
        "CACHE_STORE": "array",
        "QUEUE_CONNECTION": "database",
        "MAIL_MAILER": "array",
        "BABI_VACCINE_CATALOG_VERSION": "BROWSER-TEST-ONLY",
        "BABI_PRIVATE_MEDIA_ROOT": str(root / "private-storage"),
        "BABI_WEB_ORIGINS": origin,
        "BABI_PHP": php_executable,
        "BABI_BACKEND": str(backend),
        "NODE_PATH": node_modules,
    }
    (root / "browser-smoke.sqlite").touch()
    api_server = web_server = None
    log_handles = []
    preserve_browser_failure = os.environ.get("BABI_KEEP_BROWSER_FAILURE") == "1"
    keep_failure_data = False

    def php(*args, cwd=backend):
        result = subprocess.run(
            [php_executable, "-d", "extension=gd", *args],
            cwd=cwd,
            env=env,
            capture_output=True,
            text=True,
        )
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    try:
        php("artisan", "migrate", "--force", "--no-interaction")
        seed = root / "seed.php"
        seed.write_text(
            "<?php\nrequire " + repr(str(backend / "vendor/autoload.php").replace("\\", "/")) + ";\n"
            "$app = require " + repr(str(backend / "bootstrap/app.php").replace("\\", "/")) + ";\n"
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n"
            "Illuminate\\Support\\Facades\\DB::table('vaccine_templates')->insert(["
            "'vaccine_code'=>'BROWSER-TEST','name'=>'Synthetic browser test only','dose_number'=>1,"
            "'disease'=>'TEST ONLY','program'=>'expanded','schedule_version'=>'BROWSER-TEST-ONLY',"
            "'timing_rule'=>'clinical','source_url'=>'https://example.invalid/test-only',"
            "'created_at'=>now(),'updated_at'=>now()]);\n",
            encoding="utf-8",
        )
        php(str(seed))

        api_log = root / "api-server.log"
        api_handle = api_log.open("w", encoding="utf-8")
        log_handles.append(api_handle)
        api_server = subprocess.Popen(
            [
                php_executable,
                "-d",
                "extension=gd",
                "-d",
                "upload_max_filesize=50M",
                "-d",
                "post_max_size=512M",
                "-S",
                f"127.0.0.1:{api_port}",
                str(backend / "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"),
            ],
            cwd=backend / "public",
            env=env,
            stdout=api_handle,
            stderr=api_handle,
        )
        wait_for_port(api_port, api_server, api_log)

        token = None

        def call(method, path, data=None, headers=None, raw=False, expected=200):
            body = data if isinstance(data, bytes) else json.dumps(data).encode() if data is not None else None
            request_headers = {
                "Accept": "application/json",
                "Content-Type": "application/json",
                **({"Authorization": "Bearer " + token} if token else {}),
                **(headers or {}),
            }
            request = urllib.request.Request(api_url + "/" + path, data=body, headers=request_headers, method=method)
            try:
                response = urllib.request.urlopen(request, timeout=20)
            except urllib.error.HTTPError as error:
                response = error
            content = response.read()
            assert response.status == expected, (path, response.status, content[:500])
            return (content, response.headers) if raw else json.loads(content)

        account = call(
            "POST",
            "auth/register",
            {
                "name": "Browser smoke",
                "email": "browser-smoke@example.invalid",
                "password": "test-password-123",
                "password_confirmation": "test-password-123",
            },
            expected=201,
        )
        token = account["data"]["token"]
        version = call("GET", "app-config")["data"]["policy_version"]
        child = call(
            "POST",
            "child-profiles",
            {
                "name": "Browser test child",
                "date_of_birth": "2025-01-01",
                "gender": "female",
                "representative_confirmed": True,
                "storage_consent": True,
                "family_sharing_acknowledged": True,
                "policy_version": version,
            },
            headers={"X-Operation-Key": str(uuid.uuid4())},
            expected=201,
        )
        child_id = child["data"]["id"]

        package_config = json.loads((frontend / ".dart_tool" / "package_config.json").read_text(encoding="utf-8"))
        video_player_package = next(item for item in package_config["packages"] if item["name"] == "video_player")
        package_root = Path(video_player_package["rootUri"].removeprefix("file:///").replace("/", "\\"))
        mp4_fixture = package_root / "example" / "assets" / "Butterfly-209.mp4"
        if not mp4_fixture.is_file():
            raise RuntimeError(f"Flutter video_player MP4 fixture was not found: {mp4_fixture}")
        boundary = "----BabiBrowserSmoke" + uuid.uuid4().hex
        parts = []
        for name, value in (
            ("child_profile_id", str(child_id)),
            ("caption", "MP4 browser playback test"),
            ("memory_date", time.strftime("%Y-%m-%d")),
        ):
            parts.append(
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode()
            )
        parts.append(
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"playback-test.mp4\"\r\nContent-Type: video/mp4\r\n\r\n".encode()
            + mp4_fixture.read_bytes()
            + b"\r\n"
        )
        parts.append(f"--{boundary}--\r\n".encode())
        mp4_memory = call(
            "POST",
            "memories",
            b"".join(parts),
            headers={
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "X-Operation-Key": str(uuid.uuid4()),
            },
            expected=201,
        )["data"]
        if mp4_memory["status"] != "uploading":
            raise RuntimeError(f"MP4 playback fixture was not queued: {mp4_memory}")

        web_build = frontend / "build" / "browser-smoke"
        marker = web_build / ".babi-test-api"
        source_times = [path.stat().st_mtime for folder in (frontend / "lib", frontend / "assets") for path in folder.rglob("*") if path.is_file()]
        source_times.extend(path.stat().st_mtime for path in (frontend / "pubspec.yaml", frontend / "pubspec.lock"))
        needs_build = (
            not (web_build / "main.dart.js").exists()
            or not marker.exists()
            or marker.read_text(encoding="utf-8") != api_url
            or (web_build / "main.dart.js").stat().st_mtime < max(source_times)
        )
        if needs_build:
            subprocess.run(
                [
                    flutter_executable,
                    "build",
                    "web",
                    "--release",
                    "--no-pub",
                    f"--dart-define=BABI_API_URL={api_url}",
                    "--output",
                    str(web_build),
                ],
                cwd=frontend,
                env=env,
                check=True,
            )
            marker.write_text(api_url, encoding="utf-8")
        web_log = root / "web-server.log"
        web_handle = web_log.open("w", encoding="utf-8")
        log_handles.append(web_handle)
        web_server = subprocess.Popen(
            [python_executable, "-m", "http.server", str(web_port), "--bind", "127.0.0.1", "--directory", str(web_build)],
            cwd=frontend,
            env=env,
            stdout=web_handle,
            stderr=web_handle,
        )
        wait_for_port(web_port, web_server, web_log)

        result = subprocess.run(
            [
                node_executable,
                str(frontend / "scripts/camera_browser_smoke.cjs"),
                origin,
                "browser-smoke@example.invalid",
                "test-password-123",
                api_url,
                token,
                str(mp4_memory["id"]),
                str(root / "browser-failure.png"),
            ],
            cwd=frontend,
            env=env,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=180,
        )
        if result.returncode:
            failure_screenshot = root / "browser-failure.png"
            if failure_screenshot.exists():
                destination = frontend / "artifacts" / "camera-browser-failure.png"
                shutil.copyfile(failure_screenshot, destination)
                print(f"Browser screenshot for diagnosis: {destination}")
            if preserve_browser_failure:
                keep_failure_data = True
                print(f"Browser smoke data retained for diagnosis: {root}")
            raise RuntimeError((result.stdout or "") + (result.stderr or ""))
        browser_result = json.loads(result.stdout.strip().splitlines()[-1])

        memory = call("GET", f"memories/{browser_result['memoryId']}")["data"]
        assert memory["status"] == "published", memory
        assert len(memory["media"]) == 2, memory["media"]
        image = next(item for item in memory["media"] if item["file_type"] == "image")
        video = next(item for item in memory["media"] if item["file_type"] == "video")
        assert video["mime_type"] == "video/webm", video
        image_bytes, image_headers = call("GET", f"media-files/{image['id']}", raw=True)
        video_bytes, video_headers = call("GET", f"media-files/{video['id']}", raw=True)
        assert image_bytes.startswith(b"\xff\xd8\xff"), image_bytes[:12]
        assert video_bytes.startswith(bytes.fromhex("1a45dfa3")), video_bytes[:12]
        assert "private" in image_headers.get("Cache-Control", "").lower(), image_headers
        assert "private" in video_headers.get("Cache-Control", "").lower(), video_headers
        mp4_memory_record = call("GET", f"memories/{mp4_memory['id']}")["data"]
        assert mp4_memory_record["status"] == "published", mp4_memory_record
        mp4_video = next(item for item in mp4_memory_record["media"] if item["file_type"] == "video")
        assert mp4_video["mime_type"] == "video/mp4", mp4_video
        mp4_bytes, mp4_headers = call("GET", f"media-files/{mp4_video['id']}", raw=True)
        assert b"ftyp" in mp4_bytes[:32], mp4_bytes[:32]
        assert "private" in mp4_headers.get("Cache-Control", "").lower(), mp4_headers
        print(
            "PASS: Chrome fake camera captured JPEG and WebM; Flutter uploaded both in one memory; "
            "Laravel queue published them; authenticated private endpoints returned both media; "
            f"Chrome decoded and played WebM and MP4: {json.dumps(browser_result.get('playback'))}."
        )
        shutil.rmtree(web_build, ignore_errors=True)
    finally:
        for process in (web_server, api_server):
            if process is not None:
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        for handle in log_handles:
            handle.close()
        if not keep_failure_data:
            shutil.rmtree(root, ignore_errors=True)


if __name__ == "__main__":
    main()
