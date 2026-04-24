<?php

declare(strict_types=1);

namespace App\Serializer;

use ArrayObject;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Backward-compatible alias for stale compiled containers.
 * Safe to remove after cache clear/restart on all runtimes.
 */
final class ContractCollectionHideSourceCodeNormalizer implements NormalizerInterface
{
    public function __construct()
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): ArrayObject|array|string|int|float|bool|null
    {
        // Inert fallback for stale containers. This class should not be used in fresh container builds.
        return null;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return false;
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['*' => false];
    }
}
