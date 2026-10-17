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
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal kernel for integration testing the bundle.
 *
 * Framework config is version-guarded so it boots on Symfony 5.4 -> 8. Doctrine
 * runs against an in-memory SQLite database, or the database named by the
 * SYSTEM_EMAIL_TEST_DATABASE_URL environment variable (resolved at runtime, so one
 * compiled container serves both); the mailer uses {@see TestTransport}.
 * In the "async" environments e-mail is queued on an in-memory Messenger transport
 * (one retry) and handled inside the doctrine_transaction middleware, which rolls
 * back what the handler wrote when it fails; otherwise it is handled synchronously.
 * In the "failover" environments the mailer tries test://down before test://default.
 * The e-mail log is on, except in the "nolog" environments and on symfony/mailer
 * before 6.2, which it does not support.
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
            'mailer' => ['dsn' => str_contains($this->environment, 'failover') ? 'failover(test://down test://default)' : 'test://default'],
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ];

        if (Kernel::VERSION_ID >= 60400) {
            $framework['handle_all_throwables'] = true;
        }

        if (str_contains($this->environment, 'async')) {
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

        $container->setParameter('env(SYSTEM_EMAIL_TEST_DATABASE_URL)', 'sqlite:///:memory:');
        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'url' => '%env(SYSTEM_EMAIL_TEST_DATABASE_URL)%',
            ],
            'orm' => $orm,
        ]);

        $container->loadFromExtension('twig', [
            'strict_variables' => true,
        ]);

        $container->loadFromExtension('system_email', [
            'mail_log' => [
                'enabled' => !str_contains($this->environment, 'nolog') && class_exists(FailedMessageEvent::class),
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
