<?php

namespace App\Actions\Identity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OneTimeCodeService
{
    public function issue(string $destination, string $purpose): string
    {
        DB::table('one_time_codes')->where('destination', $destination)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
        $code = (string) random_int(100000, 999999);
        DB::table('one_time_codes')->insert(['id' => (string) Str::ulid(), 'destination' => $destination, 'purpose' => $purpose, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

        return $code;
    }

    public function consume(string $destination, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($destination, $purpose, $code): bool {
            $row = DB::table('one_time_codes')->where('destination', $destination)->where('purpose', $purpose)->whereNull('consumed_at')->latest()->lockForUpdate()->first();
            if (! $row || now()->isAfter(Carbon::parse($row->expires_at)) || $row->attempts >= $row->max_attempts) {
                return false;
            }
            if (! hash_equals($row->code_hash, hash('sha256', $code))) {
                DB::table('one_time_codes')->where('id', $row->id)->increment('attempts');

                return false;
            }
            DB::table('one_time_codes')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);

            return true;
        });
    }
}
