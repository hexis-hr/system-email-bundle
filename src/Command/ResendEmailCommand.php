<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Command;

use Hexis\SystemEmailBundle\Enum\EmailLogStatus;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;
use Hexis\SystemEmailBundle\Service\EmailResender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resends a logged e-mail, to its original recipients or to --to addresses.
 * The resend is logged as a new entry linked to the original.
 */
#[AsCommand(
    name: 'system-email:log:resend',
    description: 'Resend a logged e-mail as a new log entry linked to the original.',
)]
final class ResendEmailCommand extends Command
{
    public function __construct(
        private EmailResender $resender,
        private SystemEmailLogRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Id of the e-mail log entry to resend.')
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Corrected recipient (repeatable). Replaces To; Cc and Bcc are dropped.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = (string) $input->getArgument('id');
        $entry = ctype_digit($id) ? $this->repository->find((int) $id) : null;
        if ($entry === null) {
            $io->error(sprintf('No e-mail log entry #%s.', $id));

            return Command::FAILURE;
        }

        /** @var list<string> $to */
        $to = $input->getOption('to');
        $resend = $this->resender->resend($entry, $to === [] ? null : $to);

        if ($resend->getStatus() === EmailLogStatus::FAILED) {
            $io->error(sprintf('Resent e-mail #%s as #%d, which failed: %s', $id, (int) $resend->getId(), (string) $resend->getLastError()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Resent e-mail #%s as #%d (%s).', $id, (int) $resend->getId(), $resend->getStatus()->value));

        return Command::SUCCESS;
    }
}
