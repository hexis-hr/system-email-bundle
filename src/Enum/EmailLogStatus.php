<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Enum;

/**
 * Delivery status of a logged outgoing e-mail.
 *
 * Persisted as its backing string value on SystemEmailLog, like RecipientType.
 * A message waiting for a Messenger retry is QUEUED again (with its last error).
 */
enum EmailLogStatus: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
}
