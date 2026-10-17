<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Hexis\SystemEmailBundle\Command\PurgeEmailLogCommand;
use Hexis\SystemEmailBundle\Command\ResendEmailCommand;
use Hexis\SystemEmailBundle\Command\SyncTypesCommand;
use Hexis\SystemEmailBundle\Controller\Admin\SystemEmailController;
use Hexis\SystemEmailBundle\Doctrine\EmailLogTableNameListener;
use Hexis\SystemEmailBundle\EventSubscriber\EmailLogSubscriber;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Hexis\SystemEmailBundle\Service\EmailLogWriter;
use Hexis\SystemEmailBundle\Service\EmailResender;
use Hexis\SystemEmailBundle\Service\SystemEmailMailer;
use Hexis\SystemEmailBundle\Service\SystemEmailManager;
use Symfony\Component\Mailer\MailerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
    $services = $container->services();

    // Doctrine repositories (resolvable via $em->getRepository() and by type-hint).
    $services->set(SystemEmailTypeRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(SystemEmailRecipientRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // Recipient resolution + per-type enabled lookups, with optional PSR-6 caching.
    // 'system_email.cache' is aliased by the extension only when caching is enabled;
    // ignoreOnInvalid() yields null otherwise.
    $services->set(SystemEmailManager::class)
        ->args([
            service(EntityManagerInterface::class),
            service(SystemEmailTypeRepository::class),
            service(SystemEmailRecipientRepository::class),
            service('system_email.cache')->ignoreOnInvalid(),
            '%system_email.cache.enabled%',
        ])
        ->public();

    // Build + deliver mail via Symfony Mailer. Messenger is optional: when the bus
    // is present and async is on, mail is dispatched as SendEmailMessage.
    $services->set(SystemEmailMailer::class)
        ->args([
            service(SystemEmailManager::class),
            service(MailerInterface::class),
            service('messenger.default_bus')->ignoreOnInvalid(),
            '%system_email.async%',
            '%system_email.from.address%',
            '%system_email.from.name%',
        ])
        ->public();

    // Deploy-time type sync command (tag works on Symfony 5.4+, unlike #[AsCommand] DI).
    $services->set(SyncTypesCommand::class)
        ->args([
            service(EntityManagerInterface::class),
            service(SystemEmailTypeRepository::class),
            '%system_email.types%',
            '%system_email.default_type_enabled%',
        ])
        ->tag('console.command');

    // Outgoing e-mail log: read model, table name, DBAL writer and the Mailer/Messenger
    // event subscriber that feeds it (it only writes when mail_log.enabled is true).
    $services->set(SystemEmailLogRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(EmailLogTableNameListener::class)
        ->args(['%system_email.mail_log.table_name%'])
        ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

    $services->set(EmailLogWriter::class)
        ->args([
            service('doctrine'),
            '%system_email.mail_log.table_name%',
            service('logger')->ignoreOnInvalid(),
        ]);

    $services->set(EmailLogSubscriber::class)
        ->args([
            service(EmailLogWriter::class),
            '%system_email.mail_log.enabled%',
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(EmailResender::class)
        ->args([
            service(EmailLogWriter::class),
            service(SystemEmailLogRepository::class),
            service(MailerInterface::class),
            '%system_email.mail_log.enabled%',
        ])
        ->public();

    $services->set(ResendEmailCommand::class)
        ->args([
            service(EmailResender::class),
            service(SystemEmailLogRepository::class),
        ])
        ->tag('console.command');

    $services->set(PurgeEmailLogCommand::class)
        ->args([service(EmailLogWriter::class)])
        ->tag('console.command');

    // Backend management controller (AbstractController; autoconfigure wires setContainer).
    $services->set(SystemEmailController::class)
        ->args([
            service(SystemEmailManager::class),
            service(SystemEmailTypeRepository::class),
            service(SystemEmailRecipientRepository::class),
            '%system_email.admin.role%',
            '%system_email.admin.base_template%',
            '%system_email.translation_domain%',
        ])
        ->autowire()
        ->autoconfigure()
        ->tag('controller.service_arguments')
        ->public();
};
