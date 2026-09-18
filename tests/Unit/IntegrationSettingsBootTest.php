<?php

namespace Tests\Unit;

use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IntegrationSettingsBootTest extends TestCase
{
    public function test_apply_to_config_skips_the_database_during_package_discover(): void
    {
        $previous = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'package:discover', '--ansi'];
        Schema::shouldReceive('hasTable')->never();

        try {
            app(IntegrationSettings::class)->applyToConfig();
        } finally {
            $_SERVER['argv'] = $previous;
        }

        $this->addToAssertionCount(1);
    }

    public function test_apply_to_config_does_not_throw_when_the_database_is_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->andThrow(new \RuntimeException('the database system is shutting down'));

        app(IntegrationSettings::class)->applyToConfig();

        $this->addToAssertionCount(1);
    }
}
