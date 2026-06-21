<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Hexis\SystemEmailBundle\Service\SystemEmailMailer;
use Hexis\SystemEmailBundle\Service\SystemEmailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class SystemEmailMailerTest extends TestCase
{
    public function testDisabledTypeSendsNothing(): void
    {
        $manager = $this->manager(
            new SystemEmailType('disabled', 'Disabled', false),
            [],
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $service = new SystemEmailMailer($manager, $mailer, null, false, 'from@example.com', 'From');

        self::assertFalse($service->send('disabled', 'Subject', '<p>Body</p>'));
    }

    public function testEnabledTypeWithoutRecipientsSendsNothing(): void
    {
        $manager = $this->manager(new SystemEmailType('active', 'Active', true), []);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $service = new SystemEmailMailer($manager, $mailer, null, false, 'from@example.com', 'From');

        self::assertFalse($service->send('active', 'Subject', '<p>Body</p>'));
    }

    public function testEnabledTypeBuildsToCcBcc(): void
    {
        $manager = $this->manager(new SystemEmailType('active', 'Active', true), [
            $this->recipient('to@example.com', 'To Person', 'to'),
            $this->recipient('cc@example.com', 'Cc Person', 'cc'),
            $this->recipient('bcc@example.com', 'Bcc Person', 'bcc'),
        ]);

        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (Email $message) use (&$captured): void {
                $captured = $message;
            });

        $service = new SystemEmailMailer($manager, $mailer, null, false, 'from@example.com', 'From');

        self::assertTrue($service->send('active', 'Subject', '<p>Body</p>'));
        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('Subject', $captured->getSubject());
        self::assertSame('from@example.com', $captured->getFrom()[0]->getAddress());
        self::assertSame('to@example.com', $captured->getTo()[0]->getAddress());
        self::assertSame('cc@example.com', $captured->getCc()[0]->getAddress());
        self::assertSame('bcc@example.com', $captured->getBcc()[0]->getAddress());
    }

    /**
     * @param SystemEmailRecipient[] $recipients
     */
    private function manager(SystemEmailType $type, array $recipients): SystemEmailManager
    {
        $typeRepository = $this->createMock(SystemEmailTypeRepository::class);
        $typeRepository->method('findOneByKey')->willReturn($type);

        $recipientRepository = $this->createMock(SystemEmailRecipientRepository::class);
        $recipientRepository->method('findActiveByTypeKey')->willReturn($recipients);

        return new SystemEmailManager(
            $this->createMock(EntityManagerInterface::class),
            $typeRepository,
            $recipientRepository,
            null,
            false,
        );
    }

    private function recipient(string $email, string $name, string $type): SystemEmailRecipient
    {
        return (new SystemEmailRecipient())
            ->setEmail($email)
            ->setName($name)
            ->setRecipientType($type)
            ->setActive(true);
    }
}
