<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Hexis\SystemEmailBundle\Enum\RecipientType;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A recipient attached to a {@see SystemEmailType}. Fully managed in the admin UI.
 */
#[ORM\Entity(repositoryClass: SystemEmailRecipientRepository::class)]
#[ORM\Table(name: 'system_email_recipient')]
#[ORM\Index(name: 'idx_system_email_recipient_active', columns: ['type_id', 'active'])]
class SystemEmailRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SystemEmailType::class, inversedBy: 'recipients')]
    #[ORM\JoinColumn(name: 'type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SystemEmailType $type = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Backing value of {@see RecipientType}; TO as a literal, PHP 8.1 cannot read ->value in a default. */
    #[ORM\Column(name: 'recipient_type', type: 'string', length: 10)]
    #[Assert\Choice(callback: [RecipientType::class, 'choices'])]
    private string $recipientType = 'to';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?SystemEmailType
    {
        return $this->type;
    }

    public function setType(?SystemEmailType $type): self
    {
        $this->type = $type;

        return $this;
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

    public function getRecipientType(): string
    {
        return $this->recipientType;
    }

    public function setRecipientType(string $recipientType): self
    {
        $this->recipientType = $recipientType;

        return $this;
    }

    public function getRecipientTypeEnum(): RecipientType
    {
        return RecipientType::from($this->recipientType);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }
}
