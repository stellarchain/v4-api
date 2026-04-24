<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\Contract;
use ArrayObject;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ContractCollectionJsonLdNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const CONTEXT_FLAG = 'contract_collection_jsonld_normalizer_applied';

    public function normalize(mixed $data, ?string $format = null, array $context = []): ArrayObject|array|string|int|float|bool|null
    {
        if (($context[self::CONTEXT_FLAG] ?? false) === true) {
            return $this->normalizer->normalize($data, $format, $context);
        }

        $context[self::CONTEXT_FLAG] = true;

        $ignored = $context[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [];
        if (!is_array($ignored)) {
            $ignored = [];
        }
        $operation = $context['operation'] ?? null;
        if ($operation instanceof GetCollection && !in_array('sourceCode', $ignored, true)) {
            $ignored[] = 'sourceCode';
        }
        $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = $ignored;

        return $this->normalizer->normalize($data, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (($context['resource_class'] ?? null) !== Contract::class) {
            return false;
        }

        if (($context[self::CONTEXT_FLAG] ?? false) === true) {
            return false;
        }

        return true;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            '*' => false,
        ];
    }
}
