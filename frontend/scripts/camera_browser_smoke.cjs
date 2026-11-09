const { chromium } = require('playwright');

const [origin, email, password, apiUrl, token, mp4MemoryId, failureScreenshot] = process.argv.slice(2);
if (!origin || !email || !password || !apiUrl || !token || !mp4MemoryId || !failureScreenshot) {
  throw new Error('Usage: camera_browser_smoke.cjs <origin> <email> <password> <api-url> <token> <mp4-memory-id> <screenshot>');
}

async function playPrivateVideo(page, url, bearer, expectedMime) {
  return page.evaluate(async ({ url, bearer, expectedMime }) => {
    const response = await fetch(url, {
      headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(`Private video fetch returned HTTP ${response.status}`);
    const mimeType = response.headers.get('content-type')?.split(';')[0];
    if (mimeType !== expectedMime) throw new Error(`Private video MIME was ${mimeType}, expected ${expectedMime}`);
    const blob = await response.blob();
    if (blob.size === 0 || blob.type !== expectedMime) {
      throw new Error(`Downloaded video blob was invalid: ${blob.type}, ${blob.size} bytes`);
    }
    const objectUrl = URL.createObjectURL(blob);
    const player = document.createElement('video');
    player.muted = true;
    player.src = objectUrl;
    document.body.appendChild(player);
    await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error(`${expectedMime} decode timed out: ${blob.size} bytes`)), 10000);
      player.addEventListener('canplay', resolve, { once: true });
      player.addEventListener('canplay', () => clearTimeout(timeout), { once: true });
      player.addEventListener('error', () => {
        const mediaError = player.error;
        reject(new Error(`Browser could not decode ${expectedMime} (${mediaError?.code}: ${mediaError?.message}); ${blob.size} bytes`));
      }, { once: true });
    });
    await player.play();
    await new Promise((resolve, reject) => {
      const deadline = Date.now() + 5000;
      const check = () => {
        if (!player.paused && player.currentTime > 0) return resolve();
        if (Date.now() > deadline) return reject(new Error(`Uploaded ${expectedMime} did not advance during playback.`));
        setTimeout(check, 50);
      };
      check();
    });
    const result = { mimeType, readyState: player.readyState, currentTime: player.currentTime, advanced: player.currentTime > 0 };
    player.pause();
    URL.revokeObjectURL(objectUrl);
    player.remove();
    return result;
  }, { url, bearer, expectedMime });
}

async function main() {
  const browser = await chromium.launch({
    executablePath: process.env.BABI_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true,
    args: [
      '--use-fake-device-for-media-stream',
      '--use-fake-ui-for-media-stream',
      '--autoplay-policy=no-user-gesture-required',
    ],
  });
  const context = await browser.newContext({
    permissions: ['camera', 'microphone'],
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  page.on('pageerror', (error) => console.error('Browser page error:', error.stack || error.message));

  try {
    await page.goto(origin, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => document.querySelector('flt-glass-pane') !== null,
      null,
      { timeout: 60000 },
    );
    await page.waitForTimeout(1000);
    const enableAccessibility = page.getByRole('button', {
      name: 'Enable accessibility',
    });
    await enableAccessibility.waitFor({ state: 'visible', timeout: 30000 });
    await enableAccessibility.evaluate((element) => element.click());

    const emailField = page.getByLabel('Email');
    const passwordField = page.getByLabel('Mật khẩu');
    await emailField.waitFor({ state: 'visible' });
    // Flutter Web needs keyboard events for its FormField controller; setting
    // the native input value with fill() alone can leave the password validator
    // seeing an empty value even though the DOM input appears populated.
    await emailField.click();
    await emailField.press('Control+A');
    await emailField.pressSequentially(email);
    await passwordField.click();
    await passwordField.press('Control+A');
    await passwordField.pressSequentially(password);
    if ((await emailField.inputValue()) !== email || (await passwordField.inputValue()) !== password) {
      throw new Error('The browser smoke test could not enter the synthetic login credentials.');
    }
    // "Đăng nhập" now selects the auth tab; the form submits via its CTA.
    await page.getByRole('button', { name: 'Vào sổ ký ức', exact: true }).click();
    await page.waitForTimeout(1500);
    const cameraTab = page.getByRole('button', { name: 'Chụp', exact: true }).last();
    await cameraTab.waitFor();
    await cameraTab.click();
    await page.waitForFunction(() => [...document.querySelectorAll('video')]
      .some(video => video.readyState >= 2 && video.videoWidth > 0), null, { timeout: 20000 });
    console.log('After camera tab:', (await page.locator('body').innerText()).slice(0, 500));

    const shutter = page.getByRole('button', {
      name: 'Chụp ảnh; giữ để quay video',
    });
    await shutter.waitFor();
    await shutter.click();
    await page.getByText(/^1\s*\/\s*1 tệp$/).waitFor();

    await page.getByRole('button', { name: 'Quay lại' }).click();
    await shutter.waitFor();
    await page.waitForTimeout(2000);
    const shutterBounds = await shutter.boundingBox();
    if (!shutterBounds) throw new Error('Camera shutter has no visible bounds.');
    const shutterX = shutterBounds.x + shutterBounds.width / 2;
    const shutterY = shutterBounds.y + shutterBounds.height / 2;
    await page.mouse.move(shutterX, shutterY);
    await page.mouse.down();
    await page.waitForTimeout(3500);
    await page.mouse.up();
    await page.getByText(/^2\s*\/\s*2 tệp$/).waitFor();

    await page.getByText('Thêm caption (bắt buộc)', { exact: false }).click();
    await page.getByLabel('Caption (bắt buộc)').fill('Camera browser smoke test');
    await page.getByRole('button', { name: 'Xong' }).click();

    const uploadResponsePromise = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/memories') &&
        response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Lưu hồ sơ bé', exact: true }).click();
    const uploadResponse = await uploadResponsePromise;
    if (uploadResponse.status() !== 201) {
      throw new Error(`Camera upload returned HTTP ${uploadResponse.status()}`);
    }
    const payload = await uploadResponse.json();
    const memory = payload.data;
    if (!memory?.id || memory.status !== 'uploading') {
      throw new Error(`Unexpected upload result: ${JSON.stringify(memory)}`);
    }

    const { spawnSync } = require('node:child_process');
    const php = process.env.BABI_PHP || 'php';
    const worker = spawnSync(
      php,
      ['-d', 'extension=gd', 'artisan', 'queue:work', '--stop-when-empty', '--tries=3', '--no-interaction'],
      { cwd: process.env.BABI_BACKEND, env: process.env, encoding: 'utf8' },
    );
    if (worker.status !== 0) throw new Error(`Queue worker failed: ${worker.stderr || worker.stdout}`);
    console.log('Queue processing:', worker.stdout);

    const publishedResponse = await page.request.get(`${apiUrl}/memories/${memory.id}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const publishedPayload = await publishedResponse.json();
    if (publishedResponse.status() !== 200 || publishedPayload.data?.status !== 'published') {
      throw new Error(`Memory was not published: ${JSON.stringify(publishedPayload)}`);
    }
    const video = publishedPayload.data.media.find((item) => item.file_type === 'video');
    if (!video || video.mime_type !== 'video/webm') {
      throw new Error(`Published memory has no WebM video: ${JSON.stringify(video)}`);
    }
    const playback = await playPrivateVideo(page, `${apiUrl}/media-files/${video.id}`, token, 'video/webm');
    if (!playback.advanced || playback.readyState < 2) {
      throw new Error(`WebM playback failed: ${JSON.stringify(playback)}`);
    }

    const mp4MemoryResponse = await page.request.get(`${apiUrl}/memories/${mp4MemoryId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const mp4MemoryPayload = await mp4MemoryResponse.json();
    if (mp4MemoryResponse.status() !== 200 || mp4MemoryPayload.data?.status !== 'published') {
      throw new Error(`MP4 fixture memory was not published: ${JSON.stringify(mp4MemoryPayload)}`);
    }
    const mp4 = mp4MemoryPayload.data.media.find((item) => item.file_type === 'video');
    if (!mp4 || mp4.mime_type !== 'video/mp4') {
      throw new Error(`Published memory has no MP4 video: ${JSON.stringify(mp4)}`);
    }
    const mp4Playback = await playPrivateVideo(page, `${apiUrl}/media-files/${mp4.id}`, token, 'video/mp4');
    if (!mp4Playback.advanced || mp4Playback.readyState < 2) {
      throw new Error(`MP4 playback failed: ${JSON.stringify(mp4Playback)}`);
    }
    console.log(JSON.stringify({
      memoryId: memory.id,
      mp4MemoryId,
      status: 'published',
      playback: { webm: playback, mp4: mp4Playback },
    }));
  } catch (error) {
    console.error('Page text:', await page.locator('body').innerText().catch(() => 'unavailable'));
    await page.screenshot({ path: failureScreenshot }).catch(() => {});
    throw error;
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error.stack || error);
  process.exitCode = 1;
});
