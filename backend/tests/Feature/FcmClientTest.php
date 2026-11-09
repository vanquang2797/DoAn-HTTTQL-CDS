<?php

namespace Tests\Feature;

use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmPermanentException;
use App\Services\FcmRetryableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FcmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $clientEmail = 'fcm-client-'.Str::uuid().'@example.test';
        $projectId = 'babi-test-project';
        $credentialPath = tempnam(sys_get_temp_dir(), 'babi-fcm-client-');
        file_put_contents($credentialPath, json_encode([
            'client_email' => $clientEmail,
            'private_key' => 'not-used-with-a-cached-access-token',
            'project_id' => $projectId,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));
        config(['services.fcm.credentials' => $credentialPath, 'services.fcm.project_id' => $projectId]);
        Cache::put('babi:fcm:oauth:'.hash('sha256', $clientEmail.'|'.$projectId), 'test-access-token', now()->addMinutes(30));
        $this->beforeApplicationDestroyed(fn () => @unlink($credentialPath));
    }

    public function test_message_connection_loss_is_classified_as_ambiguous(): void
    {
        Http::fake(fn () => throw new ConnectionException('socket timeout'));

        try {
            app(FcmClient::class)->send('device-token', 'Title', 'Body');
            $this->fail('A dropped message connection must be reported as ambiguous.');
        } catch (FcmDeliveryUnknownException) {
            $this->assertTrue(true);
        }
    }

    public function test_provider_429_or_5xx_is_retriable(): void
    {
        Http::fake(fn () => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503));
        try {
            app(FcmClient::class)->send('device-token', 'Title', 'Body');
            $this->fail('A temporary provider error must be retriable.');
        } catch (FcmRetryableException) {
            $this->assertTrue(true);
        }
    }

    public function test_permanent_provider_rejection_is_not_marked_retriable(): void
    {
        Http::fake(fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400));
        try {
            app(FcmClient::class)->send('device-token', 'Title', 'Body');
            $this->fail('An invalid message must not be retried forever.');
        } catch (FcmPermanentException) {
            $this->assertTrue(true);
        }
    }
}
