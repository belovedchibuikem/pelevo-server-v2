<?php

namespace Tests\Unit;

use App\Support\CatalogText;
use Tests\TestCase;

final class CatalogTextTest extends TestCase
{
    public function test_scrubs_invalid_utf8_and_truncates(): void
    {
        $this->assertSame('hello', CatalogText::utf8("hello\0"));
        $this->assertSame('ab', CatalogText::utf8('abcd', 2));
        $scrubbed = CatalogText::utf8("ok\xD7");
        $this->assertTrue(mb_check_encoding($scrubbed, 'UTF-8'));
        $this->assertStringStartsWith('ok', $scrubbed);
    }
}
