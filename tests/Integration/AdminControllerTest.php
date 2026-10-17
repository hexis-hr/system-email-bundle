<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    public function testIndexListsTypes(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->persist(new SystemEmailType('welcome', 'Welcome email'));
        $em->flush();

        $client->request('GET', '/admin/system-emails/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Welcome email', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('System Emails', (string) $client->getResponse()->getContent());
    }
}
