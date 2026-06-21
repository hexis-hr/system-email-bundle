<?php

declare(strict_types=1);

use Hexis\SystemEmailBundle\Controller\Admin\SystemEmailController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import in the host app under the configured prefix, e.g.
 * config/routes/system_email.yaml:
 *
 *   system_email:
 *       resource: '@SystemEmailBundle/config/routes.php'
 *       prefix: /admin/system-emails
 *
 * Secure the prefix via the firewall; the controller also checks the configured
 * role (system_email.admin.role) when a security layer is present.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('system_email_admin_index', '/')
        ->controller([SystemEmailController::class, 'index'])
        ->methods(['GET']);

    $routes->add('system_email_admin_type_toggle', '/types/{key}/toggle')
        ->controller([SystemEmailController::class, 'toggleType'])
        ->methods(['POST']);

    $routes->add('system_email_admin_recipients', '/types/{key}/recipients')
        ->controller([SystemEmailController::class, 'recipients'])
        ->methods(['GET']);

    $routes->add('system_email_admin_recipient_new', '/types/{key}/recipients/new')
        ->controller([SystemEmailController::class, 'newRecipient'])
        ->methods(['GET', 'POST']);

    $routes->add('system_email_admin_recipient_edit', '/recipients/{id}/edit')
        ->controller([SystemEmailController::class, 'editRecipient'])
        ->methods(['GET', 'POST'])
        ->requirements(['id' => '\d+']);

    $routes->add('system_email_admin_recipient_toggle', '/recipients/{id}/toggle')
        ->controller([SystemEmailController::class, 'toggleRecipient'])
        ->methods(['POST'])
        ->requirements(['id' => '\d+']);

    $routes->add('system_email_admin_recipient_delete', '/recipients/{id}/delete')
        ->controller([SystemEmailController::class, 'deleteRecipient'])
        ->methods(['POST'])
        ->requirements(['id' => '\d+']);
};
