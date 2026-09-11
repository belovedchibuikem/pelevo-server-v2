<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('trust_state')->default('standard')->index();
            $table->timestampTz('last_active_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
        });
        Schema::create('device_pairing_codes', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->char('code_hash', 64)->unique();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestampTz('expires_at')->index();
            $t->timestampTz('used_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('sync_resource_states', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('resource_type', 50);
            $t->ulid('resource_id');
            $t->unsignedBigInteger('version');
            $t->json('payload');
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('deleted_at')->nullable();
            $t->timestampsTz();
            $t->unique(['user_id', 'resource_type', 'resource_id'], 'sync_resource_unique');
        });
        Schema::create('sync_conflicts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('sync_resource_state_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('client_version');
            $t->json('client_payload');
            $t->json('server_payload');
            $t->string('state')->default('open')->index();
            $t->string('resolution')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('user_backups', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('state')->default('ready')->index();
            $t->unsignedBigInteger('version');
            $t->longText('payload_encrypted');
            $t->char('checksum', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
        });
        Schema::create('share_card_templates', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->unsignedInteger('version');
            $t->json('schema');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['name', 'version']);
        });
        Schema::create('share_cards', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('share_card_template_id')->constrained()->restrictOnDelete();
            $t->string('subject_type');
            $t->ulid('subject_id');
            $t->string('state')->default('ready')->index();
            $t->json('payload');
            $t->string('public_token')->unique();
            $t->timestampTz('scheduled_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('share_card_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('share_card_id')->constrained()->cascadeOnDelete();
            $t->string('event');
            $t->string('channel')->nullable();
            $t->timestampTz('created_at');
            $t->index(['share_card_id', 'created_at']);
        });
        Schema::create('achievements', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug')->unique();
            $t->string('name');
            $t->text('description');
            $t->json('criteria');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('listening_daily_stats', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->unsignedBigInteger('listening_seconds')->default(0);
            $t->unsignedInteger('episodes_started')->default(0);
            $t->unsignedInteger('episodes_completed')->default(0);
            $t->timestampsTz();
            $t->primary(['user_id', 'date']);
        });
        Schema::create('streaks', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('current_days')->default(0);
            $t->unsignedInteger('longest_days')->default(0);
            $t->date('last_qualified_date')->nullable();
            $t->timestampsTz();
        });
        Schema::create('user_achievements', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('achievement_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('earned_at');
            $t->json('evidence');
            $t->primary(['user_id', 'achievement_id']);
        });
        Schema::create('recommendation_snapshots', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('version');
            $t->json('items');
            $t->json('explanations');
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
            $t->unique(['user_id', 'version']);
        });
        Schema::create('trending_snapshots', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('scope');
            $t->unsignedBigInteger('version');
            $t->json('items');
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
            $t->unique(['scope', 'version']);
        });
        Schema::create('referral_programs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->string('state')->default('draft')->index();
            $t->unsignedBigInteger('qualifying_seconds');
            $t->unsignedBigInteger('referrer_reward');
            $t->unsignedBigInteger('referred_reward');
            $t->unsignedInteger('daily_cap');
            $t->timestampTz('effective_at')->index();
            $t->timestampsTz();
        });
        Schema::create('referral_qualification_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('referral_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('idempotency_key')->unique();
            $t->json('evidence');
            $t->timestampsTz();
        });
        Schema::create('referral_rewards', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('referral_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('ledger_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('coins');
            $t->string('idempotency_key')->unique();
            $t->timestampsTz();
            $t->unique(['referral_id', 'user_id']);
        });
        Schema::create('notification_templates', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('key');
            $t->unsignedInteger('version');
            $t->string('title');
            $t->text('body');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['key', 'version']);
        });
        Schema::create('notification_broadcasts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('notification_template_id')->constrained()->restrictOnDelete();
            $t->json('audience');
            $t->string('state')->default('draft')->index();
            $t->timestampTz('scheduled_at')->nullable()->index();
            $t->foreignUlid('created_by')->constrained('admins')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('notification_deliveries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('notification_broadcast_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('channel');
            $t->string('state')->default('queued')->index();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('failure')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampsTz();
            $t->unique(['notification_broadcast_id', 'user_id', 'channel'], 'notification_delivery_unique');
        });
        Schema::create('notification_lock_rules', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->boolean('show_title')->default(true);
            $t->boolean('show_body')->default(false);
            $t->boolean('sensitive_hidden')->default(true);
            $t->timestampsTz();
        });
        Schema::create('prompt_versions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('type');
            $t->unsignedInteger('version');
            $t->longText('prompt');
            $t->unsignedTinyInteger('rollout_percent')->default(0);
            $t->string('state')->default('draft')->index();
            $t->timestampsTz();
            $t->unique(['type', 'version']);
        });
        Schema::create('ai_usage', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('ai_job_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('model');
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedBigInteger('cost_microusd')->default(0);
            $t->timestampsTz();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('support_tickets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject');
            $t->text('message');
            $t->string('state')->default('open')->index();
            $t->string('priority')->default('normal')->index();
            $t->foreignUlid('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $t->timestampTz('sla_due_at')->index();
            $t->timestampsTz();
        });
        Schema::create('feedback', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type');
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('message');
            $t->string('state')->default('new')->index();
            $t->timestampsTz();
        });
        Schema::create('cms_pages', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug')->unique();
            $t->string('title');
            $t->longText('body');
            $t->unsignedInteger('version');
            $t->string('state')->default('draft')->index();
            $t->timestampTz('published_at')->nullable();
            $t->foreignUlid('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::table('share_card_templates')->insert(['id' => (string) Str::ulid(), 'name' => 'Pelevo Standard', 'version' => 1, 'schema' => json_encode(['title' => true, 'artwork' => true, 'deep_link' => true]), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['help', 'Help Centre', 'Find answers and contact Pelevo support.'], ['about', 'About Pelevo', 'Pelevo connects listeners and podcast creators.']] as [$slug, $title, $body]) {
            DB::table('cms_pages')->insert(['id' => (string) Str::ulid(), 'slug' => $slug, 'title' => $title, 'body' => $body, 'version' => 1, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['first-hour', 'First Hour', 'Listen for one hour.', ['listening_seconds' => 3600]], ['ten-completions', 'Ten Finishes', 'Complete ten episodes.', ['episodes_completed' => 10]]] as [$slug, $name, $description, $criteria]) {
            DB::table('achievements')->insert(['id' => (string) Str::ulid(), 'slug' => $slug, 'name' => $name, 'description' => $description, 'criteria' => json_encode($criteria), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['cms_pages', 'feedback', 'support_tickets', 'ai_usage', 'prompt_versions', 'notification_lock_rules', 'notification_deliveries', 'notification_broadcasts', 'notification_templates', 'referral_rewards', 'referral_qualification_events', 'referral_programs', 'trending_snapshots', 'recommendation_snapshots', 'user_achievements', 'streaks', 'listening_daily_stats', 'achievements', 'share_card_events', 'share_cards', 'share_card_templates', 'user_backups', 'sync_conflicts', 'sync_resource_states', 'device_pairing_codes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['trust_state', 'last_active_at', 'last_ip']));
    }
};
