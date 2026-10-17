<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use PHPUnit\Framework\TestCase;

final class SystemEmailLogMappingTest extends TestCase
{
    public function testTheDatabaseGeneratesTheLogIdOnPostgreSql(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([\dirname(__DIR__, 2) . '/src/Entity']));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('SystemEmailLogMappingTestProxies');
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
        if (method_exists($config, 'setIdentityGenerationPreferences')) {
            // What the ORM picks for AUTO ids on PostgreSQL with DBAL 3, and recommends configuring there.
            $config->setIdentityGenerationPreferences([PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE]);
        }
        // The server version is given, so nothing connects.
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17.0']), $config);

        // EmailLogWriter inserts rows through DBAL without an id: an ORM-managed sequence would leave it NULL.
        self::assertTrue($em->getClassMetadata(SystemEmailLog::class)->isIdGeneratorIdentity());
    }
}
