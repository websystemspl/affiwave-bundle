<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Command;

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Coupon\CouponChangedEvent;
use AffiWave\Bundle\Coupon\CouponState;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Fallback sync of AffiWave coupons when a `coupon.*` webhook did not arrive (AffiWave retries a few times and
 * disables the endpoint after a run of failures). Reads `GET /api/coupon_codes?changedSince=` (API key scope
 * `reports:read`) and passes every coupon through the same path as the webhook: a {@see CouponChangedEvent} and,
 * with `coupons.enabled`, the Stripe synchroniser (which answers `disabled` otherwise). Idempotent, so it can run from cron.
 */
#[AsCommand(name: 'affiwave:coupons:sync', description: 'Sync AffiWave coupons (fallback for missed webhooks)')]
final class SyncCouponsCommand extends Command
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly AffiWaveClient $client,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?StripeCouponSynchronizer $synchronizer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Coupons created or changed since (ISO 8601 or any strtotime date)', '-2 days')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sync every coupon, ignore --since');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->client->isEnabled()) {
            $io->warning('AffiWave integration is disabled (api_url / api_key empty, or enabled: false).');

            return Command::SUCCESS;
        }

        $since = null;
        if (!$input->getOption('all')) {
            try {
                $since = new \DateTimeImmutable((string) $input->getOption('since'));
            } catch (\Exception) {
                $io->error('Invalid --since date.');

                return Command::INVALID;
            }
        }

        $counts = [];
        $failed = 0;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            try {
                $result = $this->client->listCouponCodes($since, $page);
            } catch (\Throwable $e) {
                $io->error('AffiWave API unreachable: '.$e->getMessage());

                return Command::FAILURE;
            }
            if (200 !== $result->status) {
                $io->error(\sprintf('AffiWave API returned %s (does the key have the reports:read scope?).', $result->summary()));

                return Command::FAILURE;
            }

            $items = $result->body['member'] ?? $result->body['hydra:member'] ?? $result->body;
            foreach (\is_array($items) ? $items : [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                try {
                    $state = CouponState::fromApiItem($item);
                    $eventAt = $this->changedAt($item);
                    $this->dispatcher->dispatch(new CouponChangedEvent($state, 'coupon.sync', $eventAt));
                    $key = null === $this->synchronizer ? 'dispatched' : $this->synchronizer->apply($state, $eventAt)->value;
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->warning(\sprintf('Coupon %s: %s', (string) ($item['id'] ?? '?'), $e->getMessage()));
                }
            }
            if (!\is_array($items) || \count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        ksort($counts);
        $io->success(\sprintf('Synced: %s; failed (will retry): %d.', [] === $counts ? 'nothing to do' : http_build_query($counts, '', ', '), $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param array<mixed> $item */
    private function changedAt(array $item): ?\DateTimeImmutable
    {
        $raw = $item['updatedAt'] ?? $item['createdAt'] ?? null;
        try {
            return \is_string($raw) && '' !== $raw ? new \DateTimeImmutable($raw) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
