<?php

namespace Tests\Unit;

use App\Services\Finance\TokenValuation;
use PHPUnit\Framework\TestCase;

final class TokenValuationTest extends TestCase
{
    public function test_published_matrix_matches_the_store_fee_splits(): void
    {
        $standard = TokenValuation::standard();
        $this->assertSame(12_667, $standard['gross_scaled']);
        $this->assertSame(3_800, $standard['store_fee_scaled']);
        $this->assertSame(8_867, $standard['net_scaled']);
        $this->assertSame(2_660, $standard['platform_scaled']);
        $this->assertSame(6_207, $standard['creator_scaled']);
        $this->assertSame('0.0006207', TokenValuation::formatScaled($standard['creator_scaled']));

        $small = TokenValuation::smallBusiness();
        $this->assertSame(1_900, $small['store_fee_scaled']);
        $this->assertSame(10_767, $small['net_scaled']);
        $this->assertSame(3_230, $small['platform_scaled']);
        $this->assertSame(7_537, $small['creator_scaled']);
        $this->assertSame('0.0007537', TokenValuation::formatScaled($small['creator_scaled']));
        $this->assertSame(38, TokenValuation::anchorUsdCents(300));
        $this->assertSame(1_862_100, 300 * $standard['creator_scaled']);
        $this->assertSame(2_261_100, 300 * $small['creator_scaled']);
    }
}
