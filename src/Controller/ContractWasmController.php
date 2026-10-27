<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContractRead\ContractReadGateway;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ContractWasmController
{
    public function __construct(
        private readonly ContractReadGateway $gateway,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    #[Route('/v1/contracts/{contractId}/wasm', name: 'contract_wasm', methods: ['GET'])]
    public function __invoke(string $contractId, Request $request): Response
    {
        $network = $this->networkResolver->normalizeNetwork($request->query->get('network'));
        $wasm = $this->gateway->wasm($contractId, $network);
        if ($wasm === null) {
            throw new NotFoundHttpException('No WASM binary is available for this contract on the selected network.');
        }
        $response = new Response($wasm['binary'], headers: [
            'Content-Type' => 'application/wasm',
            'Content-Length' => (string) $wasm['size'],
            'X-Content-Type-Options' => 'nosniff',
            'X-Wasm-SHA256' => $wasm['hash'],
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $contractId.'.wasm'));
        $response->setEtag($wasm['hash']);
        $response->isNotModified($request);

        return $response;
    }
}
