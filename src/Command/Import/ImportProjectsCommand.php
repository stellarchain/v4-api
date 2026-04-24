<?php

declare(strict_types=1);

namespace App\Command\Import;

use App\Service\Storage\ObjectStorageService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:projects:import',
    description: 'Import projects from JSON and upload matching local images to Spaces.',
)]
final class ImportProjectsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ObjectStorageService $objectStorageService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Projects JSON file path (relative to project root).', 'projects/stellar_projects.json')
            ->addOption('force-upload', null, InputOption::VALUE_NONE, 'Force image upload even if storage key already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and validate only, without DB writes/uploads.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $forceUpload = (bool) $input->getOption('force-upload');
        $relativeFile = trim((string) $input->getOption('file'));
        $jsonPath = $this->resolveAbsolutePath($relativeFile);

        if (!is_file($jsonPath)) {
            $io->error(sprintf('JSON file not found: %s', $jsonPath));

            return Command::FAILURE;
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            $io->error(sprintf('Cannot read JSON file: %s', $jsonPath));

            return Command::FAILURE;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $io->error(sprintf('Invalid JSON structure in %s', $jsonPath));

            return Command::FAILURE;
        }

        $imported = 0;
        $uploadedImages = 0;
        $missingImages = 0;
        $updated = 0;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($data as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }

            $sourceUrl = trim((string) ($row['url'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sourceUrl === '' || $name === '') {
                continue;
            }

            $existing = $this->connection->fetchAssociative(
                'SELECT id, image_storage_key FROM projects WHERE source_url = :source_url LIMIT 1',
                ['source_url' => $sourceUrl]
            );

            $mainImage = is_array($row['main_image'] ?? null) ? $row['main_image'] : [];
            $imageSourceFile = trim((string) ($mainImage['src_file'] ?? ''));
            $imageSourceUrl = trim((string) ($mainImage['src_url'] ?? ''));
            $imageAlt = trim((string) ($mainImage['alt'] ?? ''));

            $imageStorageKey = null;
            $imagePublicUrl = null;

            if ($imageSourceFile !== '') {
                $localImagePath = $this->resolveAbsolutePath('projects/' . ltrim($imageSourceFile, '/'));
                if (is_file($localImagePath)) {
                    $basename = basename($imageSourceFile);
                    $imageStorageKey = 'projects/' . $basename;
                    $shouldUpload = $forceUpload
                        || !is_array($existing)
                        || trim((string) ($existing['image_storage_key'] ?? '')) !== $imageStorageKey;

                    if ($shouldUpload) {
                        if (!$dryRun) {
                            $content = file_get_contents($localImagePath);
                            if ($content === false) {
                                $io->warning(sprintf('Cannot read image file for project "%s": %s', $name, $localImagePath));
                            } else {
                                $contentType = $this->detectContentType($localImagePath);
                                $imagePublicUrl = $this->objectStorageService->uploadContent(
                                    $imageStorageKey,
                                    $content,
                                    $contentType,
                                    ['source' => 'stellar_projects_import']
                                );
                                $uploadedImages++;
                            }
                        } else {
                            $uploadedImages++;
                        }
                    } elseif (is_array($existing)) {
                        $imagePublicUrl = $this->objectStorageService->buildPublicUrl($imageStorageKey);
                    }
                } else {
                    $missingImages++;
                    $io->writeln(sprintf('<comment>Missing image [%d]: %s</comment>', $idx, $localImagePath));
                }
            }

            $rounds = is_array($row['rounds'] ?? null) ? $row['rounds'] : [];
            $submissions = is_array($row['submissions'] ?? null) ? $row['submissions'] : [];
            $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];
            $roundsCount = count($rounds);
            $totalAwardedRaw = $this->normalizeNullableString($stats['total_awarded'] ?? null);
            $totalAwardedAmount = $this->parseAwardedAmount($totalAwardedRaw);

            if (!$dryRun) {
                if (is_array($existing)) {
                    $this->connection->update(
                        'projects',
                        [
                            'name' => $name,
                            'website' => $this->normalizeNullableString($row['website'] ?? null),
                            'github' => $this->normalizeNullableString($row['github'] ?? null),
                            'description' => $this->normalizeNullableString($row['description'] ?? null),
                            'rounds' => json_encode(array_values($rounds), JSON_UNESCAPED_SLASHES),
                            'submissions' => json_encode(array_values($submissions), JSON_UNESCAPED_SLASHES),
                            'team_size' => $this->normalizeNullableInt($stats['team_size'] ?? null),
                            'category' => $this->normalizeNullableString($stats['category'] ?? null),
                            'total_awarded' => $totalAwardedRaw,
                            'total_awarded_amount' => $totalAwardedAmount,
                            'rounds_count' => $roundsCount,
                            'awarded_submissions' => $this->normalizeNullableInt($stats['awarded_submissions'] ?? null),
                            'image_alt' => $this->normalizeNullableString($imageAlt),
                            'image_source_url' => $this->normalizeNullableString($imageSourceUrl),
                            'image_source_file' => $this->normalizeNullableString($imageSourceFile),
                            'image_storage_key' => $this->normalizeNullableString($imageStorageKey),
                            'image_public_url' => $this->normalizeNullableString($imagePublicUrl),
                            'updated_at' => $now->format('Y-m-d H:i:s'),
                        ],
                        ['id' => (int) $existing['id']],
                        ['id' => ParameterType::INTEGER]
                    );
                    $updated++;
                } else {
                    $this->connection->insert('projects', [
                        'source_url' => $sourceUrl,
                        'name' => $name,
                        'website' => $this->normalizeNullableString($row['website'] ?? null),
                        'github' => $this->normalizeNullableString($row['github'] ?? null),
                        'description' => $this->normalizeNullableString($row['description'] ?? null),
                        'rounds' => json_encode(array_values($rounds), JSON_UNESCAPED_SLASHES),
                        'submissions' => json_encode(array_values($submissions), JSON_UNESCAPED_SLASHES),
                        'team_size' => $this->normalizeNullableInt($stats['team_size'] ?? null),
                        'category' => $this->normalizeNullableString($stats['category'] ?? null),
                        'total_awarded' => $totalAwardedRaw,
                        'total_awarded_amount' => $totalAwardedAmount,
                        'rounds_count' => $roundsCount,
                        'awarded_submissions' => $this->normalizeNullableInt($stats['awarded_submissions'] ?? null),
                        'image_alt' => $this->normalizeNullableString($imageAlt),
                        'image_source_url' => $this->normalizeNullableString($imageSourceUrl),
                        'image_source_file' => $this->normalizeNullableString($imageSourceFile),
                        'image_storage_key' => $this->normalizeNullableString($imageStorageKey),
                        'image_public_url' => $this->normalizeNullableString($imagePublicUrl),
                        'created_at' => $now->format('Y-m-d H:i:s'),
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ]);
                }
            }

            $imported++;
        }

        $io->table(['Metric', 'Value'], [
            ['projects_processed', (string) $imported],
            ['projects_updated', (string) $updated],
            ['images_uploaded', (string) $uploadedImages],
            ['images_missing', (string) $missingImages],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        $io->success($dryRun ? 'Projects dry-run completed.' : 'Projects import completed.');

        return Command::SUCCESS;
    }

    private function resolveAbsolutePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $this->projectDir;
        }
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return rtrim($this->projectDir, '/') . '/' . ltrim($trimmed, '/');
    }

    private function detectContentType(string $path): string
    {
        $mime = @mime_content_type($path);
        if (!is_string($mime) || trim($mime) === '') {
            return 'application/octet-stream';
        }

        return $mime;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function parseAwardedAmount(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtoupper(trim($raw));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['$', ',', '*', ' '], '', $normalized);
        if ($normalized === '') {
            return null;
        }

        $multiplier = 1.0;
        if (str_ends_with($normalized, 'K')) {
            $multiplier = 1000.0;
            $normalized = substr($normalized, 0, -1);
        } elseif (str_ends_with($normalized, 'M')) {
            $multiplier = 1000000.0;
            $normalized = substr($normalized, 0, -1);
        } elseif (str_ends_with($normalized, 'B')) {
            $multiplier = 1000000000.0;
            $normalized = substr($normalized, 0, -1);
        }

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized * $multiplier;

        return number_format($amount, 2, '.', '');
    }
}
