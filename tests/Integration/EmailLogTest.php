<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Hexis\SystemEmailBundle\Enum\EmailLogStatus;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;
use Hexis\SystemEmailBundle\Service\EmailResender;
use Hexis\SystemEmailBundle\Service\SystemEmailMailer;
use Hexis\SystemEmailBundle\Service\SystemEmailManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Message;

final class EmailLogTest extends KernelTestCase
{
    protected function setUp(): void
    {
        TestTransport::reset();
    }

    public function testTableNameIsConfigurable(): void
    {
        $this->boot();

        self::assertSame('test_email_log', $this->em()->getClassMetadata(SystemEmailLog::class)->getTableName());
    }

    public function testSentEmailIsLoggedWithOneSuccessfulAttempt(): void
    {
        $this->boot();

        $this->mailer()->send($this->email());

        $entries = $this->entries();
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(EmailLogStatus::SENT, $entry->getStatus());
        self::assertSame(1, $entry->getAttemptCount());
        self::assertSame('Sender <from@example.com>', $entry->getSender());
        self::assertSame('to@example.com, cc@example.com', $entry->getRecipients());
        self::assertSame('Hello', $entry->getSubject());
        self::assertNotNull($entry->getSentAt());
        self::assertNotNull($entry->getTransportMessageId());
        self::assertNull($entry->getLastError());
        self::assertSame('sent', $entry->getAttempts()[0]['status']);

        $stored = $entry->getStoredMessage();
        self::assertInstanceOf(Email::class, $stored);
        self::assertSame('<p>Hi</p>', $stored->getHtmlBody());

        self::assertCount(1, TestTransport::$sent);
        self::assertSame($entry->getCorrelationId(), $this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    public function testTransportFailureIsLoggedAsFailedAttempt(): void
    {
        $this->boot();
        TestTransport::$fail = true;

        try {
            $this->mailer()->send($this->email());
            self::fail('The transport failure should reach the caller.');
        } catch (TransportExceptionInterface) {
        }

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::FAILED, $entry->getStatus());
        self::assertSame(1, $entry->getAttemptCount());
        self::assertSame('Forced transport failure.', $entry->getLastError());
        self::assertSame(
            [['status' => 'failed', 'error' => 'Forced transport failure.']],
            array_map(static fn (array $a): array => ['status' => $a['status'], 'error' => $a['error']], $entry->getAttempts()),
        );
        self::assertNull($entry->getSentAt());
    }

    public function testTypeAndReferenceAreLoggedButNotDelivered(): void
    {
        $this->boot();
        $type = new SystemEmailType('welcome', 'Welcome email');
        $this->em()->persist($type);
        $recipient = (new SystemEmailRecipient())->setEmail('to@example.com')->setName('To Person')->setRecipientType('to');
        $type->addRecipient($recipient);
        static::getContainer()->get(SystemEmailManager::class)->saveRecipient($recipient);

        $sent = static::getContainer()->get(SystemEmailMailer::class)
            ->send('welcome', 'Welcome', '<p>Hi</p>', fromOverride: ['address' => 'from@example.com'], reference: 'customer:42');

        self::assertTrue($sent);
        $entry = $this->entries()[0];
        self::assertSame('welcome', $entry->getType());
        self::assertSame('customer:42', $entry->getReference());
        self::assertSame(EmailLogStatus::SENT, $entry->getStatus());
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_TYPE));
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_REFERENCE));
        self::assertSame($entry->getCorrelationId(), $this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    public function testQueuedEmailIsLoggedThenSentByTheWorker(): void
    {
        $this->boot('async');
        $email = $this->email();

        $this->mailer()->send($email);

        $queued = $this->entries();
        self::assertCount(1, $queued);
        self::assertSame(EmailLogStatus::QUEUED, $queued[0]->getStatus());
        self::assertSame(0, $queued[0]->getAttemptCount());
        self::assertSame([], TestTransport::$sent);
        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_ID), 'The caller\'s message is not modified.');

        $this->consume();

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame($queued[0]->getCorrelationId(), $entries[0]->getCorrelationId());
        self::assertSame(EmailLogStatus::SENT, $entries[0]->getStatus());
        self::assertSame(1, $entries[0]->getAttemptCount());
        self::assertCount(1, TestTransport::$sent);
    }

    public function testMessengerRetriesAreAttemptsOfOneEntry(): void
    {
        $this->boot('async');
        TestTransport::$fail = true;
        $this->mailer()->send($this->email());

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::QUEUED, $entry->getStatus(), 'Back to queued while a retry is pending.');
        self::assertSame(1, $entry->getAttemptCount());
        self::assertSame('Forced transport failure.', $entry->getLastError());

        $this->consume();

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(EmailLogStatus::FAILED, $entries[0]->getStatus());
        self::assertSame(2, $entries[0]->getAttemptCount());
        self::assertSame(['failed', 'failed'], array_column($entries[0]->getAttempts(), 'status'));
    }

    public function testWorkerRecordsFailureBeforeTheTransport(): void
    {
        $this->boot('async');
        // No From: the message is rejected before it reaches the transport, so no FailedMessageEvent.
        $this->mailer()->send((new Email())->to('to@example.com')->subject('Hello')->html('<p>Hi</p>'));

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::QUEUED, $entry->getStatus());
        self::assertSame(1, $entry->getAttemptCount());
        self::assertStringContainsString('must have a "From"', (string) $entry->getLastError());
        self::assertSame([], TestTransport::$sent);
    }

    public function testFailedEmailIsResentAsNewLinkedEntry(): void
    {
        $this->boot();
        TestTransport::$fail = true;
        try {
            $this->mailer()->send($this->email());
        } catch (TransportExceptionInterface) {
        }
        TestTransport::$fail = false;
        $original = $this->entries()[0];

        $resend = static::getContainer()->get(EmailResender::class)->resend($original);

        self::assertSame(EmailLogStatus::SENT, $resend->getStatus());
        self::assertSame($original->getId(), $resend->getResentFrom()?->getId());
        self::assertNotSame($original->getCorrelationId(), $resend->getCorrelationId());
        self::assertSame($original->getRecipients(), $resend->getRecipients());

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(EmailLogStatus::FAILED, $entries[0]->getStatus());
        self::assertSame(1, $entries[0]->getAttemptCount());
        self::assertSame(
            [$entries[1]->getId()],
            array_map(static fn (SystemEmailLog $l): ?int => $l->getId(), $this->repository()->findResends($entries[0])),
        );
        self::assertCount(1, TestTransport::$sent);
        self::assertSame($resend->getCorrelationId(), $this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    public function testResendCommandQueuesToCorrectedRecipients(): void
    {
        $this->boot('async');
        TestTransport::$fail = true;
        $this->mailer()->send($this->email());
        $this->consume();
        $this->consume();
        TestTransport::$fail = false;
        $original = $this->entries()[0];
        self::assertSame(EmailLogStatus::FAILED, $original->getStatus());

        $tester = $this->command('system-email:log:resend');
        $tester->execute(['id' => (string) $original->getId(), '--to' => ['fixed@example.com']]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('(queued)', $tester->getDisplay());

        $resend = $this->entries()[1];
        self::assertSame(EmailLogStatus::QUEUED, $resend->getStatus());
        self::assertSame('fixed@example.com', $resend->getRecipients());
        self::assertSame($original->getId(), $resend->getResentFrom()?->getId());

        $this->consume();

        self::assertSame(EmailLogStatus::SENT, $this->entries()[1]->getStatus());
        $delivered = TestTransport::$sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $delivered);
        self::assertSame('fixed@example.com', $delivered->getTo()[0]->getAddress());
        self::assertSame([], $delivered->getCc());
    }

    public function testResendRefusesWhenAnAttachedFileIsGone(): void
    {
        $this->boot();
        $file = (string) tempnam(sys_get_temp_dir(), 'attachment');
        file_put_contents($file, 'report');
        $this->mailer()->send($this->email()->attachFromPath($file, 'report.txt'));
        unlink($file);
        $original = $this->entries()[0];

        try {
            static::getContainer()->get(EmailResender::class)->resend($original);
            self::fail('The resend should fail on the missing attachment file.');
        } catch (InvalidArgumentException) {
        }

        self::assertCount(1, $this->entries(), 'No entry for a resend that could not start.');
    }

    public function testResendCommandRejectsUnknownEntry(): void
    {
        $this->boot();

        $tester = $this->command('system-email:log:resend');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => '999']));
    }

    public function testPurgeDeletesEntriesOlderThanTheRetention(): void
    {
        $this->boot();
        $this->mailer()->send($this->email('old@example.com'));
        $this->mailer()->send($this->email('new@example.com'));
        $old = $this->entries()[0];
        $this->em()->getConnection()->update(
            'test_email_log',
            ['created_at' => new \DateTimeImmutable('-40 days')],
            ['id' => $old->getId()],
            ['created_at' => Types::DATETIME_IMMUTABLE],
        );

        $invalid = $this->command('system-email:log:purge');
        self::assertSame(Command::INVALID, $invalid->execute([]));

        $tester = $this->command('system-email:log:purge');
        $tester->execute(['--older-than' => '30']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 ', $tester->getDisplay());

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertStringStartsWith('new@example.com', $entries[0]->getRecipients());
    }

    public function testSearchFiltersAndPages(): void
    {
        $this->boot();
        $this->mailer()->send($this->email('alice@example.com'));
        TestTransport::$fail = true;
        try {
            $this->mailer()->send($this->email('bob@example.com'));
        } catch (TransportExceptionInterface) {
        }
        TestTransport::$fail = false;
        $this->mailer()->send($this->email('carol@example.com'));
        $this->em()->clear();
        $repository = $this->repository();

        $failed = iterator_to_array($repository->search(status: EmailLogStatus::FAILED));
        self::assertCount(1, $failed);
        self::assertStringStartsWith('bob@example.com', $failed[0]->getRecipients());

        $byRecipient = iterator_to_array($repository->search(recipient: 'carol'));
        self::assertCount(1, $byRecipient);
        self::assertStringStartsWith('carol@example.com', $byRecipient[0]->getRecipients());

        self::assertCount(0, $repository->search(since: new \DateTimeImmutable('+1 hour')));
        self::assertCount(3, $repository->search(until: new \DateTimeImmutable('+1 hour')));

        $firstPage = $repository->search(perPage: 2);
        self::assertCount(3, $firstPage, 'count() is the total number of matches');
        self::assertCount(2, iterator_to_array($firstPage));
        $secondPage = iterator_to_array($repository->search(page: 2, perPage: 2));
        self::assertCount(1, $secondPage);
        self::assertStringStartsWith('alice@example.com', $secondPage[0]->getRecipients(), 'Newest first.');
    }

    private function boot(string $environment = 'test'): void
    {
        self::bootKernel(['environment' => $environment]);
        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private function email(string $to = 'to@example.com'): Email
    {
        return (new Email())
            ->from('Sender <from@example.com>')
            ->to($to)
            ->cc('cc@example.com')
            ->subject('Hello')
            ->html('<p>Hi</p>');
    }

    /**
     * @return SystemEmailLog[] oldest first, freshly loaded
     */
    private function entries(): array
    {
        $this->em()->clear();

        return $this->repository()->findBy([], ['id' => 'ASC']);
    }

    private function deliveredHeader(int $index, string $name): ?string
    {
        $message = TestTransport::$sent[$index]->getOriginalMessage();
        self::assertInstanceOf(Message::class, $message);
        $value = $message->getHeaders()->getHeaderBody($name);

        return \is_string($value) ? $value : null;
    }

    private function consume(): void
    {
        $tester = $this->command('messenger:consume');
        $tester->execute(['receivers' => ['async'], '--limit' => '1', '--time-limit' => '5', '--no-reset' => null]);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find($name));
    }

    private function mailer(): MailerInterface
    {
        return static::getContainer()->get(MailerInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repository(): SystemEmailLogRepository
    {
        return static::getContainer()->get(SystemEmailLogRepository::class);
    }
}
