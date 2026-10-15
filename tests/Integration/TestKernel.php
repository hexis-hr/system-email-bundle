<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Hexis\SystemEmailBundle\SystemEmailBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal kernel for integration testing the bundle.
 *
 * Framework config is version-guarded so it boots on Symfony 5.4 -> 8. Doctrine
 * runs against an in-memory SQLite database; the mailer uses {@see TestTransport}.
 * In the "async" environment e-mail is queued on an in-memory Messenger transport
 * (one retry) and handled inside the doctrine_transaction middleware, which rolls
 * back what the handler wrote when it fails; otherwise it is handled synchronously.
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
            'mailer' => ['dsn' => 'test://default'],
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ];

        if (Kernel::VERSION_ID >= 60400) {
            $framework['handle_all_throwables'] = true;
        }

        if ($this->environment === 'async') {
            $framework['messenger'] = [
                'buses' => [
                    'messenger.bus.default' => ['middleware' => ['doctrine_transaction']],
                ],
                'transports' => [
                    'async' => [
                        'dsn' => 'in-memory://?serialize=true',
                        'retry_strategy' => ['max_retries' => 1, 'delay' => 0],
                    ],
                ],
                'routing' => [SendEmailMessage::class => 'async'],
            ];
        }

        $container->loadFromExtension('framework', $framework);

        // Keeps the expected Messenger failure logs of the retry tests out of the test output.
        $container->register('logger', NullLogger::class);

        $container->register(TestTransportFactory::class)
            ->setArguments([new Reference('event_dispatcher')])
            ->addTag('mailer.transport_factory');

        // DoctrineBundle 3 removed the proxy option (native lazy objects are always on).
        $orm = version_compare((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '3.0', '<')
            ? ['auto_generate_proxy_classes' => true]
            : [];

        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'url' => 'sqlite:///:memory:',
            ],
            'orm' => $orm,
        ]);

        $container->loadFromExtension('twig', [
            'strict_variables' => true,
        ]);

        $container->loadFromExtension('system_email', [
            'mail_log' => [
                'enabled' => true,
                'table_name' => 'test_email_log',
            ],
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
