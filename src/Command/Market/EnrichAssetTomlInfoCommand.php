<?php

declare(strict_types=1);

namespace App\Command\Market;

use App\Command\Support\NetworkOptionTrait;
use App\Service\AssetTomlEnrichmentService;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:market:enrich-assets-toml',
    description: 'Enrich local assets with TOML metadata from Horizon home_domain + stellar.toml.',
)]
final class EnrichAssetTomlInfoCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly AssetTomlEnrichmentService $assetTomlEnrichmentService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to enrich (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Assets per DB batch', 200)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to process (0 = no limit)', 0)
            ->addOption('market-top', null, InputOption::VALUE_REQUIRED, 'Only enrich top N assets from market snapshot (0 = all assets)', 1000)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process all assets, including already enriched')
            ->addOption('skip-logo-check', null, InputOption::VALUE_NONE, 'Skip remote logo URL validation (faster)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without DB writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $dryRun = (bool) $input->getOption('dry-run');
        $onlyMissing = !(bool) $input->getOption('all');

        $batchSize = max(1, min(1000, (int) $input->getOption('batch-size')));
        $limitRaw = (int) $input->getOption('limit');
        $limit = $limitRaw > 0 ? $limitRaw : null;
        $marketTopRaw = (int) $input->getOption('market-top');
        $marketTop = $marketTopRaw > 0 ? $marketTopRaw : null;
        $checkLogos = !(bool) $input->getOption('skip-logo-check');

        try {
            $result = $this->assetTomlEnrichmentService->enrich(
                $network,
                $dryRun,
                $batchSize,
                $limit,
                $onlyMissing,
                $marketTop,
                $checkLogos
            );
        } catch (\Throwable $exception) {
            $io->error(sprintf('Asset TOML enrichment failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['processed', (string) $result['processed']],
                ['updated', (string) $result['updated']],
                ['skipped', (string) $result['skipped']],
                ['failed', (string) $result['failed']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );

        $io->success($dryRun ? 'Dry-run completed.' : 'Asset TOML enrichment completed.');

        return Command::SUCCESS;
    }
}
