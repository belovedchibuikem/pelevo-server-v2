<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class LedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_posts_balanced_entries_once_for_repeated_idempotency_key(): void
    {
        $source = FinancialAccount::create(['type' => 'platform', 'unit' => 'PCN', 'balance' => 100]);
        $destination = FinancialAccount::create(['type' => 'creator_balance', 'unit' => 'PCN', 'balance' => 0]);
        $action = app(PostLedgerTransaction::class);
        $first = $action->handle('test', 'same-key', 'PCN', [['account_id' => $source->id, 'amount' => -40], ['account_id' => $destination->id, 'amount' => 40]]);
        $second = $action->handle('test', 'same-key', 'PCN', [['account_id' => $source->id, 'amount' => -40], ['account_id' => $destination->id, 'amount' => 40]]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(60, $source->fresh()->balance);
        $this->assertSame(40, $destination->fresh()->balance);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_rejects_unbalanced_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PostLedgerTransaction::class)->handle('test', 'bad-key', 'PCN', [['account_id' => 'missing', 'amount' => 1], ['account_id' => 'missing', 'amount' => 1]]);
    }
}
