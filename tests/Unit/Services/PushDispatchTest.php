<?php

namespace Tests\Unit\Services;

use App\Services\PushDispatch;
use PHPUnit\Framework\TestCase;

final class PushDispatchTest extends TestCase
{
    public function test_fcm_payload_includes_lock_screen_alert_and_string_data(): void
    {
        $message = (new PushDispatch)->buildMessage('device-token', [
            'type' => 'new_episode',
            'title' => 'Episode title',
            'body' => 'A new episode is available.',
            'data' => [
                'episode_id' => 'ep-1',
                'show_id' => 'show-1',
            ],
        ]);

        $this->assertSame('device-token', $message['token']);
        $this->assertSame('Episode title', $message['notification']['title']);
        $this->assertSame('A new episode is available.', $message['notification']['body']);
        $this->assertSame('HIGH', $message['android']['priority']);
        $this->assertSame('pelevo_alerts', $message['android']['notification']['channel_id']);
        $this->assertSame('new_episode', $message['data']['type']);
        $this->assertSame('ep-1', $message['data']['episode_id']);
        $this->assertSame('show-1', $message['data']['show_id']);
        $this->assertSame('Episode title', $message['data']['title']);
        $this->assertSame('A new episode is available.', $message['data']['body']);
        $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
    }
}
