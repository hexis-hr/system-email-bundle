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
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Stamp\ValidationStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
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
        $this->createWelcomeType();

        $this->sendWelcome();

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
        self::assertStringContainsString('No e-mail log entry #999.', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'abc']));
    }

    public function testPurgeDeletesEntriesOlderThanTheRetention(): void
    {
        $this->boot();
        $this->mailer()->send($this->email('old@example.com'));
        $this->mailer()->send($this->email('new@example.com'));
        $old = $this->entries()[0];
        $this->setCreatedAt($old, new \DateTimeImmutable('-40 days'));

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

    public function testSameEmailObjectSentTwiceIsLoggedTwice(): void
    {
        $this->boot();
        $email = $this->email();

        $this->mailer()->send($email);
        $this->mailer()->send($email);

        self::assertFalse($email->getHeaders()->has(SystemEmailLog::HEADER_ID), 'The caller\'s message is not tagged.');
        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertNotSame($entries[0]->getCorrelationId(), $entries[1]->getCorrelationId());
        self::assertSame([1, 1], [$entries[0]->getAttemptCount(), $entries[1]->getAttemptCount()]);
    }

    public function testMailerTypeAndReferenceSurviveTheQueue(): void
    {
        $this->boot('async');
        $this->createWelcomeType();

        $this->sendWelcome([new ValidationStamp(['queued'])]);

        $queued = $this->entries()[0];
        self::assertSame(EmailLogStatus::QUEUED, $queued->getStatus());
        self::assertSame('welcome', $queued->getType());
        self::assertSame('customer:42', $queued->getReference());
        $envelope = $this->asyncTransport()->getSent()[0];
        self::assertNotNull($envelope->last(ValidationStamp::class), 'The tagged copy keeps the dispatch stamps.');
        $message = $envelope->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $message);
        self::assertInstanceOf(Message::class, $message->getMessage());
        self::assertSame($queued->getCorrelationId(), $message->getMessage()->getHeaders()->getHeaderBody(SystemEmailLog::HEADER_ID));

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::SENT, $entry->getStatus());
        self::assertSame('welcome', $entry->getType());
        self::assertSame('customer:42', $entry->getReference());
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_TYPE));
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_REFERENCE));
        self::assertSame($entry->getCorrelationId(), $this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    public function testRetryThatSucceedsCompletesTheSameEntry(): void
    {
        $this->boot('async');
        TestTransport::$fail = true;
        $this->mailer()->send($this->email());
        $this->consume();
        TestTransport::$fail = false;

        $this->consume();

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(EmailLogStatus::SENT, $entries[0]->getStatus());
        self::assertSame(2, $entries[0]->getAttemptCount());
        self::assertSame(['failed', 'sent'], array_column($entries[0]->getAttempts(), 'status'));
        self::assertNotNull($entries[0]->getSentAt());
        self::assertCount(1, TestTransport::$sent);
        self::assertSame($entries[0]->getCorrelationId(), $this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    public function testRetryThatSucceedsStoresTheDeliveredMessage(): void
    {
        $this->boot('async');
        $this->createWelcomeType();
        TestTransport::$fail = true;
        $this->sendWelcome();
        // The worker's transaction is rolled back, with the snapshot it took before this attempt.
        $this->consume();
        TestTransport::$fail = false;

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::SENT, $entry->getStatus());
        $stored = $entry->getStoredMessage();
        self::assertInstanceOf(Message::class, $stored);
        self::assertFalse($stored->getHeaders()->has(SystemEmailLog::HEADER_TYPE), 'The stored message is the delivered one, not the queued one.');
        self::assertSame('welcome', $entry->getType());
    }

    public function testFailoverTransportsAreAttemptsOfOneQueuedEntry(): void
    {
        $this->boot('async_failover');
        $this->mailer()->send($this->email());

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::SENT, $entry->getStatus());
        self::assertSame(2, $entry->getAttemptCount());
        self::assertSame([['failed', 'Transport down.'], ['sent', null]], $this->attempts($entry));
        self::assertCount(1, TestTransport::$sent);
    }

    public function testFailoverTransportsThatAllFailAreEachRecordedOnce(): void
    {
        $this->boot('async_failover');
        TestTransport::$fail = true;
        $this->mailer()->send($this->email());

        $this->consume();

        $entry = $this->entries()[0];
        self::assertSame(EmailLogStatus::QUEUED, $entry->getStatus(), 'Back to queued while a retry is pending.');
        self::assertSame(2, $entry->getAttemptCount());
        self::assertSame([['failed', 'Transport down.'], ['failed', 'Forced transport failure.']], $this->attempts($entry));
        self::assertSame('Forced transport failure.', $entry->getLastError());
    }

    public function testDisabledLogWritesNothingAndStillStripsTheLogHeaders(): void
    {
        $this->boot('nolog');
        $this->createWelcomeType();

        $this->sendWelcome();

        self::assertSame([], $this->entries());
        self::assertCount(1, TestTransport::$sent);
        $this->assertNoLogHeaderDelivered();
    }

    public function testDisabledLogWritesNothingForQueuedEmail(): void
    {
        $this->boot('async_nolog');
        $this->createWelcomeType();

        $this->sendWelcome();
        $this->consume();

        self::assertSame([], $this->entries());
        self::assertCount(1, TestTransport::$sent);
        $this->assertNoLogHeaderDelivered();
    }

    public function testResendIsRefusedWhileTheLogIsDisabled(): void
    {
        $this->boot('nolog');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('system_email.mail_log.enabled');

        $this->resender()->resend(new SystemEmailLog());
    }

    public function testLogWriteFailureDoesNotStopTheEmail(): void
    {
        $this->boot();
        $this->em()->getConnection()->executeStatement('DROP TABLE test_email_log');

        $this->mailer()->send($this->email());

        self::assertCount(1, TestTransport::$sent);
    }

    public function testLogWriteFailureDoesNotStopQueuedEmail(): void
    {
        $this->boot('async');
        $this->em()->getConnection()->executeStatement('DROP TABLE test_email_log');

        $this->mailer()->send($this->email());
        $this->consume();

        self::assertCount(1, TestTransport::$sent);
    }

    public function testResendToCorrectedRecipientsReplacesToAndDropsCcAndBcc(): void
    {
        $this->boot();
        $this->mailer()->send($this->email()->bcc('bcc@example.com'));
        $original = $this->entries()[0];

        $resend = $this->resender()->resend($original, ['fixed@example.com', new Address('other@example.com', 'Other')]);

        self::assertSame(EmailLogStatus::SENT, $resend->getStatus());
        self::assertSame('fixed@example.com, other@example.com', $resend->getRecipients());
        $delivered = TestTransport::$sent[1]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $delivered);
        self::assertSame(['fixed@example.com', 'other@example.com'], array_map(static fn (Address $a): string => $a->getAddress(), $delivered->getTo()));
        self::assertSame([], $delivered->getCc());
        self::assertSame([], $delivered->getBcc());

        $original = $this->entries()[0];
        self::assertSame('to@example.com, cc@example.com, bcc@example.com', $original->getRecipients());
        $stored = $original->getStoredMessage();
        self::assertInstanceOf(Email::class, $stored);
        self::assertSame('to@example.com', $stored->getTo()[0]->getAddress());
        self::assertSame('bcc@example.com', $stored->getBcc()[0]->getAddress(), 'The stored original is not changed.');
    }

    public function testResendNeedsAtLeastOneCorrectedRecipient(): void
    {
        $this->boot();
        $this->mailer()->send($this->email());

        try {
            $this->resender()->resend($this->entries()[0], []);
            self::fail('An empty list of corrected recipients should be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertCount(1, $this->entries());
        self::assertCount(1, TestTransport::$sent);
    }

    public function testResendCommandResendsToTheOriginalRecipients(): void
    {
        $this->boot();
        $this->createWelcomeType();
        $this->sendWelcome();
        $original = $this->entries()[0];

        $tester = $this->command('system-email:log:resend');
        $tester->execute(['id' => (string) $original->getId()]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('(sent)', $tester->getDisplay());
        $resend = $this->entries()[1];
        self::assertSame(EmailLogStatus::SENT, $resend->getStatus());
        self::assertSame($original->getId(), $resend->getResentFrom()?->getId());
        self::assertSame($original->getRecipients(), $resend->getRecipients());
        self::assertSame('welcome', $resend->getType());
        self::assertSame('customer:42', $resend->getReference());
        self::assertCount(2, TestTransport::$sent);
        self::assertSame($resend->getCorrelationId(), $this->deliveredHeader(1, SystemEmailLog::HEADER_ID));
    }

    public function testResendCommandReportsAFailedResend(): void
    {
        $this->boot();
        $this->mailer()->send($this->email());
        $original = $this->entries()[0];
        TestTransport::$fail = true;

        $tester = $this->command('system-email:log:resend');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => (string) $original->getId()]));
        self::assertStringContainsString('which failed: Forced transport failure.', $this->display($tester));
        $resend = $this->entries()[1];
        self::assertSame(EmailLogStatus::FAILED, $resend->getStatus());
        self::assertSame('Forced transport failure.', $resend->getLastError());
        self::assertSame($original->getId(), $resend->getResentFrom()?->getId());
        self::assertSame(EmailLogStatus::SENT, $this->entries()[0]->getStatus());
    }

    public function testResendCommandRefusesAnEntryWithoutAStoredMessage(): void
    {
        $this->boot();
        $this->mailer()->send($this->email());
        $id = (int) $this->entries()[0]->getId();
        $this->em()->getConnection()->update('test_email_log', ['message' => null], ['id' => $id]);
        $this->em()->clear();

        try {
            $this->command('system-email:log:resend')->execute(['id' => (string) $id]);
            self::fail('An entry without a stored message cannot be resent.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('has no stored message', $e->getMessage());
        }

        self::assertCount(1, $this->entries());
        self::assertCount(1, TestTransport::$sent);
    }

    public function testPurgeRejectsARetentionThatIsNotAWholeNumberOfDays(): void
    {
        $this->boot();
        $this->mailer()->send($this->email());
        $this->setCreatedAt($this->entries()[0], new \DateTimeImmutable('-40 days'));

        foreach (['0', 'abc', '-5', '1.5'] as $days) {
            $tester = $this->command('system-email:log:purge');
            self::assertSame(Command::INVALID, $tester->execute(['--older-than' => $days]), $days);
            self::assertStringContainsString('--older-than=<days>, a whole number of at least 1', $this->display($tester));
        }

        self::assertCount(1, $this->entries());
    }

    public function testPurgeKeepsTheResendOfAPurgedEntry(): void
    {
        $this->boot();
        $this->mailer()->send($this->email());
        $original = $this->entries()[0];
        $resend = $this->resender()->resend($original);
        $this->setCreatedAt($original, new \DateTimeImmutable('-40 days'));

        $tester = $this->command('system-email:log:purge');
        $tester->execute(['--older-than' => '30']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 ', $tester->getDisplay());
        self::assertSame(
            [$resend->getId()],
            array_map(static fn (SystemEmailLog $l): ?int => $l->getId(), $this->entries()),
        );
    }

    public function testSearchFiltersByTypeAndDateRange(): void
    {
        $this->boot();
        $this->mailer()->send($this->email('first@example.com', 'welcome'));
        $this->mailer()->send($this->email('second@example.com', 'alert'));
        $this->mailer()->send($this->email('third@example.com', 'welcome'));
        foreach ($this->entries() as $i => $entry) {
            $this->setCreatedAt($entry, new \DateTimeImmutable(sprintf('2026-01-0%d 12:00:00', $i + 1)));
        }
        $this->em()->clear();
        $repository = $this->repository();
        $day2 = new \DateTimeImmutable('2026-01-02 12:00:00');
        $day3 = new \DateTimeImmutable('2026-01-03 12:00:00');

        self::assertSame(['third', 'first'], $this->firstRecipients($repository->search(type: 'welcome')));
        self::assertSame(['second'], $this->firstRecipients($repository->search(type: 'alert')));
        self::assertSame(['third', 'second'], $this->firstRecipients($repository->search(since: $day2)), 'since is inclusive');
        self::assertSame(['first'], $this->firstRecipients($repository->search(until: $day2)), 'until is exclusive');
        self::assertSame(['second'], $this->firstRecipients($repository->search(since: $day2, until: $day3)));
        self::assertSame(['third'], $this->firstRecipients($repository->search(type: 'welcome', since: $day2)));
        self::assertSame([], $this->firstRecipients($repository->search(status: EmailLogStatus::FAILED, type: 'welcome')));
        self::assertCount(3, $repository->search(recipient: 'cc@example'), 'Cc addresses match too.');
        self::assertCount(3, $repository->search(type: '', recipient: ''), 'Empty filters are ignored.');
    }

    private function boot(string $environment = 'test'): void
    {
        if (!str_contains($environment, 'nolog') && !class_exists(FailedMessageEvent::class)) {
            self::markTestSkipped('The e-mail log requires symfony/mailer 6.2 or later.');
        }

        self::bootKernel(['environment' => $environment]);
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function createWelcomeType(): void
    {
        $type = new SystemEmailType('welcome', 'Welcome email');
        $this->em()->persist($type);
        $recipient = (new SystemEmailRecipient())->setEmail('to@example.com')->setName('To Person')->setRecipientType('to');
        $type->addRecipient($recipient);
        static::getContainer()->get(SystemEmailManager::class)->saveRecipient($recipient);
    }

    /**
     * @param object[] $stamps
     */
    private function sendWelcome(array $stamps = []): void
    {
        $sent = static::getContainer()->get(SystemEmailMailer::class)
            ->send('welcome', 'Welcome', '<p>Hi</p>', fromOverride: ['address' => 'from@example.com'], stamps: $stamps, reference: 'customer:42');

        self::assertTrue($sent);
    }

    private function assertNoLogHeaderDelivered(): void
    {
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_TYPE));
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_REFERENCE));
        self::assertNull($this->deliveredHeader(0, SystemEmailLog::HEADER_ID));
    }

    private function setCreatedAt(SystemEmailLog $entry, \DateTimeImmutable $createdAt): void
    {
        $this->em()->getConnection()->update(
            'test_email_log',
            ['created_at' => $createdAt],
            ['id' => $entry->getId()],
            ['created_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param iterable<SystemEmailLog> $entries
     *
     * @return list<string> local part of each entry's first recipient
     */
    private function firstRecipients(iterable $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $names[] = explode('@', $entry->getRecipients())[0];
        }

        return $names;
    }

    /**
     * @return list<array{0: string, 1: string|null}> status and error of each attempt
     */
    private function attempts(SystemEmailLog $entry): array
    {
        return array_map(static fn (array $a): array => [$a['status'], $a['error']], $entry->getAttempts());
    }

    /**
     * The command output with its line wrapping undone.
     */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function email(string $to = 'to@example.com', ?string $type = null): Email
    {
        $email = (new Email())
            ->from('Sender <from@example.com>')
            ->to($to)
            ->cc('cc@example.com')
            ->subject('Hello')
            ->html('<p>Hi</p>');
        if ($type !== null) {
            $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, $type);
        }

        return $email;
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

    private function resender(): EmailResender
    {
        return static::getContainer()->get(EmailResender::class);
    }

    private function asyncTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
