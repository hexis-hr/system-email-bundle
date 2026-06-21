<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;

/**
 * A configurable email "type" (an arbitrary system functionality that sends mail).
 *
 * Identity (`key`, `label`) is synced from the YAML registry by system-email:sync.
 * `enabled` is the per-type send kill-switch, owned by the admin UI and never
 * overwritten by the sync command.
 */
#[ORM\Entity(repositoryClass: SystemEmailTypeRepository::class)]
#[ORM\Table(name: 'system_email_type')]
class SystemEmailType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Machine key referenced from code (column named to avoid the SQL reserved word). */
    #[ORM\Column(name: 'type_key', type: 'string', length: 100, unique: true)]
    private string $key;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /** @var Collection<int, SystemEmailRecipient> */
    #[ORM\OneToMany(
        mappedBy: 'type',
        targetEntity: SystemEmailRecipient::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $recipients;

    public function __construct(string $key, string $label, bool $enabled = true)
    {
        $this->key = $key;
        $this->label = $label;
        $this->enabled = $enabled;
        $this->recipients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /** @return Collection<int, SystemEmailRecipient> */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    public function addRecipient(SystemEmailRecipient $recipient): self
    {
        if (!$this->recipients->contains($recipient)) {
            $this->recipients->add($recipient);
            $recipient->setType($this);
        }

        return $this;
    }

    public function removeRecipient(SystemEmailRecipient $recipient): self
    {
        $this->recipients->removeElement($recipient);

        return $this;
    }
}
