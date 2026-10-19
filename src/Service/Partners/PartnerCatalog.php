<?php

declare(strict_types=1);

namespace App\Service\Partners;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PartnerCatalog
{
    public const CATEGORIES = [
        'wallets' => 'Wallets',
        'exchanges' => 'Exchanges',
        'ramps' => 'On/off ramps',
        'infrastructure' => 'Infrastructure',
        'developer-tools' => 'Developer tools',
        'advertising' => 'Advertising',
    ];
    public const MAX_ENTRIES = 100;
    public const RELATIONSHIPS = ['directory', 'affiliate', 'sponsored', 'integration'];

    public function __construct(
        private readonly PartnerFileStorage $storage,
        #[Autowire('%kernel.project_dir%/resources/partners.json')]
        private readonly string $seedFile,
    ) {
    }

    public function read(): array
    {
        $entries = $this->storage->read('catalog.json', null);
        if ($entries === null) {
            $contents = file_get_contents($this->seedFile);
            if ($contents === false) {
                throw new RuntimeException('Partner catalog is unavailable.');
            }
            $entries = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        }

        return $this->validate($entries);
    }

    public function revision(array $entries): string
    {
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    public function published(): array
    {
        $entries = [];
        foreach ($this->read() as $entry) {
            if ($entry['status'] === 'active') {
                $entries[] = $entry;
            }
        }
        usort($entries, [$this, 'compareEntries']);

        return $entries;
    }

    public function replace(mixed $entries, string $expectedRevision): array
    {
        $validated = $this->validate($entries);
        $lock = $this->storage->lock('catalog');
        try {
            if (!hash_equals($this->revision($this->read()), $expectedRevision)) {
                throw new PartnerCatalogConflict('The catalog changed. Reload before saving.');
            }
            $this->storage->write('catalog.json', $validated);
        } finally {
            $this->storage->unlock($lock);
        }

        return $validated;
    }

    public function hasPublishedEntry(string $id): bool
    {
        foreach ($this->published() as $entry) {
            if ($entry['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    public function validate(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Catalog must be a list with at most 100 entries.');
        }

        $ids = [];
        $validated = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException(sprintf('Entry %d must be an object.', $index + 1));
            }
            $item = [];
            foreach (['id' => 64, 'name' => 100, 'description' => 1000, 'regionNotes' => 500, 'networkNotes' => 500] as $field => $maxLength) {
                $value = $entry[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    throw new InvalidArgumentException(sprintf('Entry %d: %s is required (maximum %d characters).', $index + 1, $field, $maxLength));
                }
                $item[$field] = trim($value);
            }
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $item['id']) || isset($ids[$item['id']])) {
                throw new InvalidArgumentException('Entry IDs must be unique lowercase slugs.');
            }
            $ids[$item['id']] = true;

            foreach (['category' => array_keys(self::CATEGORIES), 'status' => ['draft', 'active', 'inactive'], 'relationship' => self::RELATIONSHIPS] as $field => $allowed) {
                if (!in_array($entry[$field] ?? null, $allowed, true)) {
                    throw new InvalidArgumentException(sprintf('%s: invalid %s.', $item['id'], $field));
                }
                $item[$field] = $entry[$field];
            }
            if (!is_int($entry['position'] ?? null) || $entry['position'] < 0 || $entry['position'] > 9999) {
                throw new InvalidArgumentException($item['id'].': position must be an integer from 0 to 9999.');
            }
            $item['position'] = $entry['position'];
            foreach (['websiteUrl', 'trackingUrl'] as $field) {
                $url = $entry[$field] ?? null;
                if ($field === 'trackingUrl' && ($url === null || $url === '')) {
                    $item[$field] = null;
                    continue;
                }
                if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException($item['id'].': '.$field.' must be an HTTPS URL.');
                }
                $parts = parse_url($url);
                if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
                    throw new InvalidArgumentException($item['id'].': URLs must use HTTPS without credentials or whitespace.');
                }
                $item[$field] = $url;
            }
            $validated[] = $item;
        }

        return $validated;
    }

    private function compareEntries(array $left, array $right): int
    {
        return [$left['position'], $left['name'], $left['id']] <=> [$right['position'], $right['name'], $right['id']];
    }
}
