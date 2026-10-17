<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration tree.
 *
 * Example (config/packages/system_email.yaml):
 *
 *   system_email:
 *       from:
 *           address: '%env(MAIL_FROM)%'
 *           name:    '%env(MAIL_FROM_NAME)%'
 *       async: true
 *       default_type_enabled: true
 *       cache:
 *           enabled: true
 *           pool: cache.app
 *       admin:
 *           enabled: true
 *           route_prefix: /admin/system-emails
 *           role: ROLE_ADMIN
 *           base_template: '@SystemEmail/layout.html.twig'
 *       translation_domain: system_email
 *       mail_log:
 *           enabled: true
 *           table_name: system_email_log
 *       types:
 *           sap_alert: { label: 'SAP Alert' }
 *           booking:   { label: 'Booking notifications' }
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('system_email');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('from')
                    ->addDefaultsIfNotSet()
                    ->info('Default sender used when send() is called without a from override.')
                    ->children()
                        ->scalarNode('address')->defaultNull()->end()
                        ->scalarNode('name')->defaultNull()->end()
                    ->end()
                ->end()
                ->booleanNode('async')
                    ->info('Dispatch outgoing mail via Messenger (SendEmailMessage) when the message bus is available; otherwise send in-request.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('default_type_enabled')
                    ->info('Enabled value assigned to types newly created by system-email:sync.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('pool')
                            ->info('PSR-6 cache pool service id used to cache resolved recipients.')
                            ->defaultValue('cache.app')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Whether the backend management controller/routes are intended to be mounted.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('route_prefix')->defaultValue('/admin/system-emails')->end()
                        ->scalarNode('role')
                            ->info('Role required to access the management UI (checked when a security layer is present).')
                            ->defaultValue('ROLE_ADMIN')
                        ->end()
                        ->scalarNode('base_template')
                            ->info('Twig template the admin views extend. Override to embed the UI in your own layout.')
                            ->defaultValue('@SystemEmail/layout.html.twig')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('translation_domain')
                    ->info('Translation domain for type labels and admin UI strings.')
                    ->defaultValue('system_email')
                ->end()
                ->arrayNode('mail_log')
                    ->addDefaultsIfNotSet()
                    ->info('Log of the system e-mails (sent through SystemEmailMailer, sync or via Messenger), with resend. Other mail is not logged.')
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Write the log. The table is part of the bundle schema either way.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('table_name')
                            ->info('Table of the SystemEmailLog entity.')
                            ->defaultValue('system_email_log')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('types')
                    ->info('The centralized registry of email types. Source of truth synced to the database by system-email:sync.')
                    ->useAttributeAsKey('key')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('description')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
