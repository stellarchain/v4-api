<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ContractBalance;
use App\Repository\ContractBalanceReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<list<ContractBalance>>
 */
final class ContractBalanceProvider implements ProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const MAX_OFFSET = 50000;

    public function __construct(
        private readonly ContractBalanceReadRepository $readRepository,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $contractId = strtoupper(trim((string) ($uriVariables['contractId'] ?? '')));
        if ($contractId === '') {
            return [];
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $request = $this->requestStack->getCurrentRequest();
        $networkCode = $this->resolveNetworkCode($request, $filters);
        if ($networkCode === null) {
            return [];
        }

        $rows = $this->readRepository->findBalancesByContract(
            $contractId,
            $networkCode,
            $this->normalizeLimit($request?->query->get('limit', $filters['limit'] ?? null)),
            $this->normalizeOffset($request?->query->get('offset', $filters['offset'] ?? null))
        );

        $result = [];
        foreach ($rows as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                continue;
            }

            $result[] = new ContractBalance(
                $address,
                (string) ($row['balance_raw'] ?? '0'),
                (string) ($row['inflow_raw'] ?? '0'),
                (string) ($row['outflow_raw'] ?? '0'),
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function resolveNetworkCode(?Request $request, array $filters): ?int
    {
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($request?->query->get('network'))
                ? $request?->query->get('network')
                : (is_string($filters['network'] ?? null) ? $filters['network'] : null),
            'mainnet'
        );

        return $this->stellarNetworkResolver->resolveNetworkCode($network);
    }

    private function normalizeLimit(mixed $value): int
    {
        if (is_int($value)) {
            return min(self::MAX_LIMIT, max(1, $value));
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            return min(self::MAX_LIMIT, max(1, (int) trim($value)));
        }

        return self::DEFAULT_LIMIT;
    }

    private function normalizeOffset(mixed $value): int
    {
        if (is_int($value)) {
            return min(self::MAX_OFFSET, max(0, $value));
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            return min(self::MAX_OFFSET, max(0, (int) trim($value)));
        }

        return 0;
    }
}
