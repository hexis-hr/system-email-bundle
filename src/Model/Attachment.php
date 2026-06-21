<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Model;

/**
 * In-memory attachment value object accepted by the mailer.
 */
class Attachment
{
    public function __construct(
        private ?string $content = null,
        private ?string $title = null,
        private ?string $mimeType = null,
    ) {
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }
}
