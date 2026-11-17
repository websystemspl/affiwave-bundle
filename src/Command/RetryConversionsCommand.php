<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Command;

use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Repository\AffiWaveConversionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retries conversions that could not be delivered (AffiWave down, timeout, key or plan problems). Safe to run from
 * cron: AffiWave deduplicates by `external_order_id`.
 */
#[AsCommand(name: 'affiwave:conversions:retry', description: 'Retry pending and failed AffiWave conversions')]
final class RetryConversionsCommand extends Command
{
    public function __construct(
        private readonly AffiWaveConversionRepository $conversions,
        private readonly ConversionReporter $reporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max conversions per run', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent, send nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->reporter->isEnabled()) {
            $io->warning('AffiWave integration is disabled (api_url / api_key empty, or enabled: false).');

            return Command::SUCCESS;
        }

        $rows = $this->conversions->findRetryable((int) $input->getOption('limit'));
        if ($input->getOption('dry-run')) {
            foreach ($rows as $row) {
                $io->writeln(\sprintf('%s  status=%s attempts=%d', $row->getExternalOrderId(), $row->getStatus()->value, $row->getAttempts()));
            }
            $io->success(\sprintf('Dry run: %d conversion(s) would be sent.', \count($rows)));

            return Command::SUCCESS;
        }

        $delivered = $failed = $rejected = 0;
        foreach ($rows as $row) {
            $result = $this->reporter->deliver($row);
            if ($result->isSuccess()) {
                ++$delivered;
            } elseif (\AffiWave\Bundle\Conversion\ReportOutcome::Rejected === $result->outcome) {
                ++$rejected;
            } else {
                ++$failed;
            }
        }

        $io->success(\sprintf('Delivered: %d, rejected: %d, failed (will retry): %d.', $delivered, $rejected, $failed));

        return Command::SUCCESS;
    }
}
