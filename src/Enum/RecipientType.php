<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Enum;

/**
 * How a recipient is addressed on the outgoing message.
 *
 * Persisted as its backing string value on SystemEmailRecipient so the column
 * stays portable across Doctrine ORM 2.9-3 (no native enum-type mapping needed).
 */
enum RecipientType: string
{
    case TO = 'to';
    case CC = 'cc';
    case BCC = 'bcc';

    public function label(): string
    {
        return match ($this) {
            self::TO => 'To',
            self::CC => 'Cc',
            self::BCC => 'Bcc',
        };
    }

    /**
     * @return array<string, string> label => value, suitable for a ChoiceType.
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
