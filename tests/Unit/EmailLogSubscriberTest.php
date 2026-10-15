<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Unit;

use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\EventSubscriber\EmailLogSubscriber;
use Hexis\SystemEmailBundle\Service\EmailLogWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

final class EmailLogSubscriberTest extends TestCase
{
    public function testDisabledLogWritesNothingButStillStripsLogHeaders(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagerForClass');
        $subscriber = new EmailLogSubscriber(new EmailLogWriter($registry, 'system_email_log'), false);

        $email = (new Email())->from('from@example.com')->to('to@example.com')->subject('Hello')->text('Hi');
        $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, 'welcome');
        $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_REFERENCE, 'customer:42');

        $subscriber->onMessage(new MessageEvent($email, Envelope::create($email), 'test://default'));

        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_TYPE));
        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_REFERENCE));
        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_ID));
    }
}
