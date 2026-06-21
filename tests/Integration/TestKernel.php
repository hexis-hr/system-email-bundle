<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Hexis\SystemEmailBundle\SystemEmailBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal kernel for integration testing the bundle.
 *
 * Framework config is version-guarded so it boots on Symfony 5.4 -> 8. Doctrine
 * runs against an in-memory SQLite database; the mailer uses the null transport.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new TwigBundle(),
            new SystemEmailBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $framework = [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'mailer' => ['dsn' => 'null://null'],
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ];

        if (Kernel::VERSION_ID >= 60400) {
            $framework['handle_all_throwables'] = true;
        }

        $container->loadFromExtension('framework', $framework);

        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'url' => 'sqlite:///:memory:',
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
            ],
        ]);

        $container->loadFromExtension('twig', [
            'strict_variables' => true,
        ]);

        $container->loadFromExtension('system_email', [
            'types' => [
                'welcome' => ['label' => 'Welcome email'],
                'alert' => ['label' => 'System alert'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@SystemEmailBundle/config/routes.php')->prefix('/admin/system-emails');
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2) . '/var/cache/' . $this->environment;
    }

    public function getBuildDir(): string
    {
        return \dirname(__DIR__, 2) . '/var/build/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2) . '/var/log';
    }
}
