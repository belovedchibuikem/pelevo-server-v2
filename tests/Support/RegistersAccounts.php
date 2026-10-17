<?php

namespace Tests\Support;

use App\Mail\OneTimeCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

trait RegistersAccounts
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function registerAndVerify(array $payload, array $headers = []): TestResponse
    {
        Mail::fake();
        $this->withHeaders($headers)
            ->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('data.verification_required', true);

        $mail = Mail::sent(OneTimeCode::class)->first();
        $this->assertInstanceOf(OneTimeCode::class, $mail);

        return $this->withHeaders($headers)->postJson('/api/v1/auth/otp/verify', [
            'email' => $payload['email'],
            'code' => $mail->code,
            'purpose' => 'email_verification',
            'device_name' => $payload['device_name'],
        ])->assertOk();
    }
}
