<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContractRead\ContractDecompilationService;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ContractDecompileController
{
    public function __construct(
        private readonly ContractDecompilationService $decompilation,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    #[Route('/v1/contracts/{contractId}/decompile', name: 'contract_decompile', methods: ['POST'])]
    public function __invoke(string $contractId, Request $request): JsonResponse
    {
        $hash = $request->toArray()['wasmHash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
            throw new BadRequestHttpException('A valid wasmHash is required.');
        }
        $network = $this->networkResolver->normalizeNetwork($request->query->get('network'));

        return new JsonResponse($this->decompilation->decompile($contractId, $network, strtolower($hash)), headers: ['Cache-Control' => 'no-store']);
    }
}
