<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Model;

use Hexis\SystemEmailBundle\Enum\RecipientType;

/**
 * Lightweight recipient value object returned by the manager and accepted by the
 * mailer. Mirrors the shape used by the MIS application so call-sites migrate
 * with no signature changes.
 */
class Recipient
{
    public function __construct(
        private string $email,
        private string $name,
        private bool $isCC = false,
        private bool $isBCC = false,
    ) {
    }

    public static function fromType(string $email, string $name, RecipientType $type): self
    {
        return new self($email, $name, $type === RecipientType::CC, $type === RecipientType::BCC);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isCC(): bool
    {
        return $this->isCC;
    }

    public function setIsCC(bool $isCC): self
    {
        $this->isCC = $isCC;

        return $this;
    }

    public function isBCC(): bool
    {
        return $this->isBCC;
    }

    public function setIsBCC(bool $isBCC): self
    {
        $this->isBCC = $isBCC;

        return $this;
    }

    public function isTo(): bool
    {
        return !$this->isCC && !$this->isBCC;
    }
}
