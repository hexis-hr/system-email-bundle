<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncTypesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SystemEmailTypeRepository $types;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->types = $container->get(SystemEmailTypeRepository::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testSyncCreatesConfiguredTypes(): void
    {
        $this->runSync();

        $keys = array_map(static fn ($t) => $t->getKey(), $this->types->findAll());
        sort($keys);

        self::assertSame(['alert', 'welcome'], $keys);
        self::assertSame('Welcome email', $this->types->findOneByKey('welcome')?->getLabel());
    }

    public function testReSyncPreservesAdminEnabledFlag(): void
    {
        $this->runSync();

        $welcome = $this->types->findOneByKey('welcome');
        self::assertNotNull($welcome);
        self::assertTrue($welcome->isEnabled());

        // Admin disables the type, then a later deploy re-runs the sync.
        $welcome->setEnabled(false);
        $this->em->flush();
        $this->em->clear();

        $this->runSync();
        $this->em->clear();

        self::assertFalse($this->types->findOneByKey('welcome')?->isEnabled());
    }

    private function runSync(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('system-email:sync'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
