<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;

/**
 * Maps {@see SystemEmailLog} to the configured table (system_email.mail_log.table_name).
 */
final class EmailLogTableNameListener
{
    public function __construct(private string $tableName)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== SystemEmailLog::class) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->tableName]);
    }
}
