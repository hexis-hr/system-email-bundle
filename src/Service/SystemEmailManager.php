<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Hexis\SystemEmailBundle\Model\Recipient;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Reads and mutates email-type configuration: resolves active recipients,
 * exposes the per-type enabled flag, and keeps an optional PSR-6 cache coherent.
 *
 * The read API (getRecipients/getEmails/getEmail) mirrors the MIS
 * SystemEmailService so existing call-sites migrate without changes.
 */
final class SystemEmailManager
{
    private const CACHE_PREFIX = 'system_email_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemEmailTypeRepository $typeRepository,
        private SystemEmailRecipientRepository $recipientRepository,
        private ?CacheItemPoolInterface $cache = null,
        private bool $cacheEnabled = true,
    ) {
    }

    public function getType(string $key): ?SystemEmailType
    {
        return $this->typeRepository->findOneByKey($key);
    }

    /**
     * Whether sending is enabled for a type. Unknown (un-synced) types report
     * false so nothing is sent for keys that were never registered.
     */
    public function isEnabled(string $key): bool
    {
        if (!$this->isCacheable()) {
            return $this->loadEnabled($key);
        }

        try {
            $item = $this->cache->getItem($this->cacheKey('enabled', $key));
            if ($item->isHit()) {
                return (bool) $item->get();
            }

            $enabled = $this->loadEnabled($key);
            $item->set($enabled);
            $this->cache->save($item);

            return $enabled;
        } catch (InvalidArgumentException $e) {
            return $this->loadEnabled($key);
        }
    }

    /**
     * Active recipients for a type as value objects (TO/CC/BCC preserved).
     *
     * @return Recipient[]
     */
    public function getRecipients(string $key): array
    {
        if (!$this->isCacheable()) {
            return $this->loadRecipients($key);
        }

        try {
            $item = $this->cache->getItem($this->cacheKey('recipients', $key));
            if ($item->isHit()) {
                return $item->get();
            }

            $recipients = $this->loadRecipients($key);
            $item->set($recipients);
            $this->cache->save($item);

            return $recipients;
        } catch (InvalidArgumentException $e) {
            return $this->loadRecipients($key);
        }
    }

    /**
     * @return string[]
     */
    public function getEmails(string $key): array
    {
        return $this->recipientRepository->getActiveEmailsByTypeKey($key);
    }

    public function getEmail(string $key): ?string
    {
        return $this->getEmails($key)[0] ?? null;
    }

    public function setTypeEnabled(SystemEmailType $type, bool $enabled): void
    {
        $type->setEnabled($enabled);
        $this->entityManager->flush();
        $this->clearCache($type->getKey());
    }

    public function saveRecipient(SystemEmailRecipient $recipient): void
    {
        $this->entityManager->persist($recipient);
        $this->entityManager->flush();
        $this->clearCacheForRecipient($recipient);
    }

    public function setRecipientActive(SystemEmailRecipient $recipient, bool $active): void
    {
        $recipient->setActive($active);
        $this->entityManager->flush();
        $this->clearCacheForRecipient($recipient);
    }

    public function deleteRecipient(SystemEmailRecipient $recipient): void
    {
        $this->clearCacheForRecipient($recipient);
        $this->entityManager->remove($recipient);
        $this->entityManager->flush();
    }

    public function clearCache(string $key): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->deleteItem($this->cacheKey('recipients', $key));
            $this->cache->deleteItem($this->cacheKey('enabled', $key));
        } catch (InvalidArgumentException $e) {
            // Nothing to do: an unparseable key means there is nothing cached for it.
        }
    }

    private function clearCacheForRecipient(SystemEmailRecipient $recipient): void
    {
        $type = $recipient->getType();
        if ($type !== null) {
            $this->clearCache($type->getKey());
        }
    }

    private function loadEnabled(string $key): bool
    {
        $type = $this->typeRepository->findOneByKey($key);

        return $type !== null && $type->isEnabled();
    }

    /**
     * @return Recipient[]
     */
    private function loadRecipients(string $key): array
    {
        return array_map(
            static fn (SystemEmailRecipient $recipient): Recipient => Recipient::fromType(
                $recipient->getEmail(),
                $recipient->getName(),
                $recipient->getRecipientTypeEnum(),
            ),
            $this->recipientRepository->findActiveByTypeKey($key),
        );
    }

    private function isCacheable(): bool
    {
        return $this->cacheEnabled && $this->cache !== null;
    }

    private function cacheKey(string $bucket, string $key): string
    {
        return self::CACHE_PREFIX . $bucket . '_' . preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }
}
