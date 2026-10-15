<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Mailer transport for the integration tests (DSN test://default): records
 * delivered messages, or fails every attempt while $fail is set.
 */
final class TestTransport extends AbstractTransport
{
    public static bool $fail = false;

    /** @var SentMessage[] */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$fail = false;
        self::$sent = [];
    }

    protected function doSend(SentMessage $message): void
    {
        if (self::$fail) {
            throw new TransportException('Forced transport failure.');
        }

        self::$sent[] = $message;
    }

    public function __toString(): string
    {
        return 'test://default';
    }
}
