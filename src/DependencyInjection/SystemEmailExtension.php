<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Loads bundle services, exposes the processed configuration as container
 * parameters, aliases the configured cache pool, and registers Doctrine
 * attribute mapping for the bundle's entities.
 */
final class SystemEmailExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        $container->setParameter('system_email.from.address', $config['from']['address']);
        $container->setParameter('system_email.from.name', $config['from']['name']);
        $container->setParameter('system_email.async', $config['async']);
        $container->setParameter('system_email.default_type_enabled', $config['default_type_enabled']);
        $container->setParameter('system_email.cache.enabled', $config['cache']['enabled']);
        $container->setParameter('system_email.admin.enabled', $config['admin']['enabled']);
        $container->setParameter('system_email.admin.route_prefix', $config['admin']['route_prefix']);
        $container->setParameter('system_email.admin.role', $config['admin']['role']);
        $container->setParameter('system_email.admin.base_template', $config['admin']['base_template']);
        $container->setParameter('system_email.translation_domain', $config['translation_domain']);
        $container->setParameter('system_email.types', $config['types']);

        // Alias the configured PSR-6 pool so the manager can depend on a stable id.
        // When caching is disabled the alias is not defined, so the optional
        // dependency resolves to null via ->ignoreOnInvalid().
        if ($config['cache']['enabled']) {
            $container->setAlias('system_email.cache', new Alias($config['cache']['pool'], false));
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SystemEmailBundle' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/Entity',
                        'prefix' => 'Hexis\\SystemEmailBundle\\Entity',
                        'alias' => 'SystemEmail',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'system_email';
    }
}
