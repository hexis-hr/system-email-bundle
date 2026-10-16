<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Command;

use Hexis\SystemEmailBundle\Service\EmailLogWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies a retention period to the e-mail log. Nothing is deleted unless this
 * command runs, so retention is indefinite by default.
 */
#[AsCommand(
    name: 'system-email:log:purge',
    description: 'Delete e-mail log entries older than a number of days.',
)]
final class PurgeEmailLogCommand extends Command
{
    /** 100 years. */
    private const MAX_DAYS = 36500;

    public function __construct(private EmailLogWriter $writer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention in days (1 to 36500): entries created more than this many days ago are deleted.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = ltrim((string) $input->getOption('older-than'), '0');
        // The length is checked before the cast: a longer number saturates and gives a cutoff in the future.
        if (!ctype_digit($days) || \strlen($days) > 5 || (int) $days > self::MAX_DAYS) {
            $io->error(sprintf('Give the retention as --older-than=<days>, a whole number from 1 to %d.', self::MAX_DAYS));

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', (int) $days));
        $deleted = $this->writer->purgeBefore($before);

        $io->success(sprintf('Deleted %d e-mail log entries created before %s.', $deleted, $before->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }
}
