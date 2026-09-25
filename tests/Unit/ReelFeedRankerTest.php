<?php

namespace Tests\Unit;

use App\Support\ReelFeedRanker;
use Tests\TestCase;

final class ReelFeedRankerTest extends TestCase
{
    public function test_interleave_keeps_four_popular_then_one_explore(): void
    {
        $popular = array_map(fn (int $n): object => (object) ['id' => 'p'.$n], range(1, 8));
        $explore = array_map(fn (int $n): object => (object) ['id' => 'e'.$n], range(1, 2));
        $ids = array_map(fn (object $row): string => (string) $row->id, ReelFeedRanker::interleave($popular, $explore));

        $this->assertSame(['p1', 'p2', 'p3', 'p4', 'e1', 'p5', 'p6', 'p7', 'p8', 'e2'], $ids);
    }

    public function test_interleave_skips_explore_ids_already_used_as_popular(): void
    {
        $popular = [(object) ['id' => 'a'], (object) ['id' => 'b'], (object) ['id' => 'c'], (object) ['id' => 'd']];
        $explore = [(object) ['id' => 'a'], (object) ['id' => 'fresh']];
        $ids = array_map(fn (object $row): string => (string) $row->id, ReelFeedRanker::interleave($popular, $explore));

        $this->assertSame(['a', 'b', 'c', 'd', 'fresh'], $ids);
    }
}
