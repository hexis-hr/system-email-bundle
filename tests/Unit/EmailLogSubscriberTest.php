<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\EventSubscriber\EmailLogSubscriber;
use Hexis\SystemEmailBundle\Service\EmailLogWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    public function testLogWriteFailureIsReportedAndDoesNotStopTheEmail(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Could not write the e-mail log: {message}',
                self::callback(static fn (array $context): bool => $context['exception'] instanceof \LogicException),
            );
        $subscriber = new EmailLogSubscriber(new EmailLogWriter($registry, 'system_email_log'), true, $logger);

        $email = (new Email())->from('from@example.com')->to('to@example.com')->subject('Hello')->text('Hi');
        $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, 'welcome');

        $subscriber->onMessage(new MessageEvent($email, Envelope::create($email), 'test://default'));

        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_TYPE));
        self::assertTrue($email->getHeaders()->has(SystemEmailLog::HEADER_ID), 'The attempts still share one correlation id.');
    }

    public function testFailedLogWriteInsideATransactionIsRolledBackToASavepoint(): void
    {
        // On PostgreSQL a failed statement aborts the enclosing transaction unless it is rolled back to a savepoint.
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('relation "system_email_log" does not exist'));
        $connection->expects(self::once())->method('createSavepoint');
        $connection->expects(self::once())->method('rollbackSavepoint');
        $connection->expects(self::never())->method('releaseSavepoint');
        $connection->expects(self::never())->method('beginTransaction');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $subscriber = new EmailLogSubscriber(new EmailLogWriter($registry, 'system_email_log'), true, $logger);

        $email = (new Email())->from('from@example.com')->to('to@example.com')->subject('Hello')->text('Hi');
        $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, 'welcome');
        $subscriber->onMessage(new MessageEvent($email, Envelope::create($email), 'test://default'));
    }
}
