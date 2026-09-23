<?php

namespace Tests\Unit\Support;

use App\Support\PelevoMailBrand;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class PelevoMailBrandTest extends TestCase
{
    public function test_logo_file_exists_and_embeds_as_inline_cid(): void
    {
        $this->assertFileExists(PelevoMailBrand::logoPath());
        $this->assertSame('cid:pelevo-logo@pelevo', PelevoMailBrand::htmlSrc());

        $message = new Email;
        PelevoMailBrand::embed(new MessageSending($message));
        PelevoMailBrand::embed(new MessageSending($message));

        $cids = array_map(static fn ($part) => $part->getContentId(), $message->getAttachments());
        $this->assertSame([PelevoMailBrand::CID], array_values(array_filter($cids)));
        $this->assertSame('inline', $message->getAttachments()[0]->getDisposition());
    }
}
