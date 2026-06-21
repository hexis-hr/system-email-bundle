<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Unit;

use Hexis\SystemEmailBundle\Enum\RecipientType;
use Hexis\SystemEmailBundle\Model\Recipient;
use PHPUnit\Framework\TestCase;

final class RecipientTest extends TestCase
{
    public function testDefaultsToToRecipient(): void
    {
        $recipient = new Recipient('a@example.com', 'Alice');

        self::assertTrue($recipient->isTo());
        self::assertFalse($recipient->isCC());
        self::assertFalse($recipient->isBCC());
    }

    public function testFromTypeMapsCcAndBcc(): void
    {
        $cc = Recipient::fromType('c@example.com', 'Carol', RecipientType::CC);
        self::assertTrue($cc->isCC());
        self::assertFalse($cc->isTo());

        $bcc = Recipient::fromType('b@example.com', 'Bob', RecipientType::BCC);
        self::assertTrue($bcc->isBCC());
        self::assertFalse($bcc->isTo());
    }
}
