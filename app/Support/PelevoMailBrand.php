<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

final class PelevoMailBrand
{
    public const CID = 'pelevo-logo@pelevo';

    public static function logoPath(): string
    {
        return resource_path('images/mail/pelevo-logo.jpg');
    }

    public static function htmlSrc(): string
    {
        return 'cid:'.self::CID;
    }

    public static function embed(MessageSending $event): void
    {
        $path = self::logoPath();
        if (! is_file($path)) {
            return;
        }

        foreach ($event->message->getAttachments() as $part) {
            if ($part->getContentId() === self::CID) {
                return;
            }
        }

        $part = (new DataPart(new File($path), 'pelevo-logo.jpg', 'image/jpeg'))->asInline();
        $part->setContentId(self::CID);
        $event->message->addPart($part);
    }
}
