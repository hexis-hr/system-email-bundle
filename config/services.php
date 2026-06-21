<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Hexis\SystemEmailBundle\Command\SyncTypesCommand;
use Hexis\SystemEmailBundle\Controller\Admin\SystemEmailController;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
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
