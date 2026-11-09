<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmClient
{
    public function isConfigured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function send(string $token, string $title, string $body, array $data = []): ?string
    {
        $credentials = $this->credentials();
        try {
            $accessToken = $this->accessToken($credentials);
        } catch (ConnectionException $exception) {
            // No FCM message request has been made yet, so this failure is safe to retry.
            throw new FcmRetryableException('FCM OAuth token request failed.', 0, $exception);
        }
        try {
            $response = Http::withToken($accessToken)->acceptJson()->connectTimeout(5)->timeout(15)->post(
                'https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send',
                ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                ]],
            );
        } catch (ConnectionException $exception) {
            throw new FcmDeliveryUnknownException('FCM message result is unknown.', 0, $exception);
        }

        if ($response->successful()) {
            $messageId = $response->json('name');

            return is_string($messageId) ? $messageId : null;
        }

        $reason = data_get($response->json(), 'error.details.0.errorCode');
        if ($reason === 'UNREGISTERED') {
            throw new InvalidFcmTokenException;
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new FcmRetryableException('FCM temporarily rejected the notification request.');
        }

        throw new FcmPermanentException('FCM rejected the notification request.');
    }

    private function credentials(): array
    {
        $path = config('services.fcm.credentials');
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('FCM service account is not configured.');
        }
        try {
            $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('FCM service account credentials are invalid.');
        }
        $credentials['project_id'] = config('services.fcm.project_id') ?: ($credentials['project_id'] ?? null);
        if (empty($credentials['client_email']) || empty($credentials['private_key']) || empty($credentials['project_id'])) {
            throw new RuntimeException('FCM service account credentials are incomplete.');
        }
        $credentials['token_uri'] = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        return $credentials;
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = 'babi:fcm:oauth:'.hash('sha256', $credentials['client_email'].'|'.$credentials['project_id']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if (! $privateKey || ! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign FCM service account assertion.');
        }
        $assertion = $unsigned.'.'.$this->base64Url($signature);
        $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post($credentials['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        $accessToken = $response->json('access_token');
        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            if ($response->status() === 429 || $response->serverError()) {
                throw new FcmRetryableException('FCM OAuth token request failed.');
            }

            throw new FcmPermanentException('FCM OAuth credentials were rejected.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put($cacheKey, $accessToken, now()->addSeconds(max(1, min(3300, $expiresIn - 60))));

        return $accessToken;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
