<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class EnsureAdminCommand extends Command
{
    protected $signature = 'pelevo:ensure-admin
        {email : Admin email address}
        {--password= : Plain-text password (required when creating or resetting)}
        {--name= : Display name}
        {--role=superadmin : Role to attach}';

    protected $description = 'Create or update a Pelevo admin operator and attach an RBAC role (safe for Forge).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = $this->option('password');
        $name = trim((string) ($this->option('name') ?: Str::before($email, '@')));
        $roleName = strtolower(trim((string) $this->option('role')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }

        AdminAccess::ensureRbac();

        $admin = Admin::query()->where('email', $email)->first();
        if (! $admin && blank($password)) {
            $this->error(' --password is required when creating a new admin.');

            return self::FAILURE;
        }

        if (! $admin) {
            $admin = Admin::query()->create([
                'email' => $email,
                'name' => $name !== '' ? $name : 'Pelevo Operator',
                'password' => (string) $password,
                'status' => 'active',
            ]);
            $this->info("Created admin {$email}");
        } else {
            $updates = ['status' => 'active'];
            if ($name !== '') {
                $updates['name'] = $name;
            }
            if (filled($password)) {
                $updates['password'] = (string) $password;
            }
            $admin->update($updates);
            $this->info("Updated admin {$email}");
        }

        if (! in_array($roleName, AdminAccess::ROLES, true)) {
            $this->error("Role [{$roleName}] does not exist.");

            return self::FAILURE;
        }

        AdminAccess::attachRole($admin->id, $roleName);

        $this->line("Attached role [{$roleName}]");
        $this->line('Login: '.rtrim((string) config('app.url'), '/').'/admin/login');
        $this->warn('First sign-in will require authenticator MFA enrolment.');

        return self::SUCCESS;
    }
}
