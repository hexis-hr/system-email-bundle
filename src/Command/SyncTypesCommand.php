<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Syncs the YAML-declared email types (system_email.types) into the database so
 * they become manageable in the backend UI. Idempotent: run it on every deploy.
 *
 * Creates missing types (enabled = system_email.default_type_enabled) and updates
 * changed labels. The admin-owned `enabled` flag is never modified. Types absent
 * from the configuration are reported, and removed only with --prune.
 */
#[AsCommand(
    name: 'system-email:sync',
    description: 'Sync configured email types into the database.',
)]
final class SyncTypesCommand extends Command
{
    /**
     * @param array<string, array{label?: string|null, description?: string|null}> $types
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemEmailTypeRepository $typeRepository,
        private array $types,
        private bool $defaultEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'prune',
            null,
            InputOption::VALUE_NONE,
            'Delete database types (and their recipients) that are no longer present in configuration.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $prune = (bool) $input->getOption('prune');

        $created = 0;
        $updated = 0;

        foreach ($this->types as $key => $definition) {
            $label = $definition['label'] ?? $this->humanize($key);

            $type = $this->typeRepository->findOneByKey($key);
            if ($type === null) {
                $this->entityManager->persist(new SystemEmailType($key, $label, $this->defaultEnabled));
                ++$created;
                continue;
            }

            if ($type->getLabel() !== $label) {
                $type->setLabel($label);
                ++$updated;
            }
        }

        $configuredKeys = array_keys($this->types);
        $orphans = array_filter(
            $this->typeRepository->findAll(),
            static fn (SystemEmailType $type): bool => !in_array($type->getKey(), $configuredKeys, true),
        );

        $pruned = 0;
        foreach ($orphans as $orphan) {
            if ($prune) {
                $this->entityManager->remove($orphan);
                ++$pruned;
            } else {
                $io->warning(sprintf('Type "%s" exists in the database but is not configured (use --prune to remove).', $orphan->getKey()));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'System email types synced: %d created, %d updated, %d %s.',
            $created,
            $updated,
            $prune ? $pruned : count($orphans),
            $prune ? 'pruned' : 'orphaned',
        ));

        return Command::SUCCESS;
    }

    private function humanize(string $key): string
    {
        return ucfirst(trim(str_replace(['_', '-', '.'], ' ', $key)));
    }
}
