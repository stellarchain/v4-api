<?php

namespace App\Controller;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\ScamReportAuditLogger;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScamReportController
{
    private const LABEL = 'Scam';
    private const MAX_ADDRESSES_PER_REQUEST = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly string $apiToken,
        private readonly ?ScamReportAuditLogger $auditLogger = null,
    ) {
    }

    #[Route('/v1/scam-reports', name: 'scam_reports_store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $configuredToken = trim($this->apiToken);
        if ($configuredToken === '') {
            return $this->error('Scam report API token is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $presentedToken = $this->extractPresentedToken($request);
        if ($presentedToken === '' || !hash_equals($configuredToken, $presentedToken)) {
            return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
        }

        $networkInput = $payload['network'] ?? $request->query->get('network');
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null);
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $addresses = $this->extractAddresses($payload);

        if ($addresses === []) {
            return $this->error('address or addresses is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (count($addresses) > self::MAX_ADDRESSES_PER_REQUEST) {
            return $this->error(sprintf('addresses must contain at most %d entries.', self::MAX_ADDRESSES_PER_REQUEST), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invalidAddresses = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => !StrKey::isValidAccountId($address)
        ));
        if ($invalidAddresses !== []) {
            return new JsonResponse([
                'message' => 'All addresses must be valid Stellar account addresses.',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_addresses' => $invalidAddresses,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $results = $this->applyScamLabels($addresses, $network, $networkCode);

        return new JsonResponse([
            'message' => 'Scam report accepted.',
            'status' => Response::HTTP_OK,
            'data' => $results,
        ], Response::HTTP_OK);
    }

    #[Route('/v1/scam-reports/link', name: 'scam_reports_link', methods: ['GET'])]
    public function reportFromLink(Request $request): Response
    {
        $configuredToken = trim($this->apiToken);
        if ($configuredToken === '') {
            return $this->htmlMessage('Scam report unavailable', 'Scam report token is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $networkInput = $request->query->get('network');
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null);
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $address = strtoupper(trim((string) $request->query->get('address', '')));
        $expires = trim((string) $request->query->get('expires', ''));
        $signature = strtolower(trim((string) $request->query->get('signature', '')));

        if ($address === '' || $expires === '' || $signature === '') {
            return $this->htmlMessage('Scam report failed', 'The report link is missing required fields.', Response::HTTP_BAD_REQUEST);
        }

        if (!ctype_digit($expires)) {
            return $this->htmlMessage('Scam report failed', 'The report link is invalid.', Response::HTTP_BAD_REQUEST);
        }

        if ((int) $expires < time()) {
            return $this->htmlMessage('Scam report expired', 'This report link has expired.', Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = $this->buildReportLinkSignature($address, $network, $expires, $configuredToken);
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->htmlMessage('Scam report failed', 'The report link signature is invalid.', Response::HTTP_UNAUTHORIZED);
        }

        if (!StrKey::isValidAccountId($address)) {
            return $this->htmlMessage('Scam report failed', 'The address is not a valid Stellar account address.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $results = $this->applyScamLabels([$address], $network, $networkCode);
        $action = $results[0]['action'] ?? 'updated';

        return $this->htmlMessage(
            'Scam report accepted',
            sprintf('Address %s was marked as scam on %s. Action: %s.', $address, $network, $action),
            Response::HTTP_OK,
            $address
        );
    }

    #[Route('/v1/scam-reports/form', name: 'scam_reports_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $accessError = $this->validateFormAccess($request);
        if ($accessError !== null) {
            return $accessError;
        }

        return $this->htmlForm(
            $this->extractRequestValue($request, 'token')
        );
    }

    #[Route('/v1/scam-reports/form', name: 'scam_reports_form_submit', methods: ['POST'])]
    public function submitForm(Request $request): Response
    {
        $accessError = $this->validateFormAccess($request);
        if ($accessError !== null) {
            return $accessError;
        }

        $network = 'mainnet';
        $networkCode = 1;
        $rawSubmission = (string) $request->request->get('addresses', '');
        $addresses = $this->extractAddressesFromText($rawSubmission);

        if ($addresses === []) {
            $this->logFormSubmission($request, 'rejected_empty', $rawSubmission, [], [], []);

            return $this->htmlForm(
                $this->extractRequestValue($request, 'token'),
                'Enter at least one Stellar account address.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (count($addresses) > self::MAX_ADDRESSES_PER_REQUEST) {
            $this->logFormSubmission($request, 'rejected_too_many', $rawSubmission, $addresses, [], []);

            return $this->htmlForm(
                $this->extractRequestValue($request, 'token'),
                sprintf('Enter at most %d addresses.', self::MAX_ADDRESSES_PER_REQUEST),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $invalidAddresses = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => !StrKey::isValidAccountId($address)
        ));
        if ($invalidAddresses !== []) {
            $this->logFormSubmission($request, 'rejected_invalid_address', $rawSubmission, $addresses, $invalidAddresses, []);

            return $this->htmlForm(
                $this->extractRequestValue($request, 'token'),
                'Invalid Stellar account address: '.implode(', ', $invalidAddresses),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $results = $this->applyScamLabels($addresses, $network, $networkCode);
        $this->logFormSubmission($request, 'accepted', $rawSubmission, $addresses, [], $results);

        return $this->htmlResults($results, $this->extractRequestValue($request, 'token'));
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function extractAddresses(array $payload): array
    {
        $rawAddresses = $payload['addresses'] ?? null;
        if (!is_array($rawAddresses)) {
            $rawAddresses = [$payload['address'] ?? ''];
        }

        $addresses = [];
        foreach ($rawAddresses as $rawAddress) {
            if (!is_scalar($rawAddress)) {
                continue;
            }

            $address = strtoupper(trim((string) $rawAddress));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return list<string>
     */
    private function extractAddressesFromText(string $rawAddresses): array
    {
        $parts = preg_split('/[\s,;]+/', $rawAddresses) ?: [];
        $addresses = [];

        foreach ($parts as $rawAddress) {
            $address = strtoupper(trim($rawAddress));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function extractPresentedToken(Request $request): string
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        $headerToken = trim((string) $request->headers->get('X-Scam-Report-Token', ''));
        if ($headerToken !== '') {
            return $headerToken;
        }

        return trim((string) $request->query->get('token', ''));
    }

    /**
     * @param list<string> $addresses
     * @return list<array{address:string,network:string,label:string,action:string}>
     */
    private function applyScamLabels(array $addresses, string $network, int $networkCode): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $results = [];

        foreach ($addresses as $address) {
            $account = $this->accountRepository->findOneByAddressAndNetwork($address, $networkCode);
            $action = 'unchanged';

            if ($account === null) {
                $account = (new Account())
                    ->setAddress($address)
                    ->setNetwork($networkCode)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now)
                    ->setLabel(self::LABEL)
                    ->setVerified(false);
                $this->entityManager->persist($account);
                $action = 'created';
            } elseif ($account->getLabel() !== self::LABEL || $account->isVerified() !== false) {
                $action = 'updated';
            }

            if ($action !== 'unchanged') {
                $account
                    ->setLabel(self::LABEL)
                    ->setVerified(false)
                    ->setUpdatedAt($now);
            }

            $results[] = [
                'address' => $address,
                'network' => $network,
                'label' => self::LABEL,
                'action' => $action,
            ];
        }

        $this->entityManager->flush();

        return $results;
    }

    private function buildReportLinkSignature(string $address, string $network, string $expires, string $token): string
    {
        return hash_hmac('sha256', $address."\n".$network."\n".$expires, $token);
    }

    private function validateFormAccess(Request $request): ?Response
    {
        $configuredToken = trim($this->apiToken);
        if ($configuredToken === '') {
            return $this->htmlMessage('Scam report unavailable', 'Scam report token is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $presentedToken = $this->extractRequestValue($request, 'token');

        if ($presentedToken === '') {
            return $this->htmlMessage('Scam report failed', 'The form link is missing the token.', Response::HTTP_BAD_REQUEST);
        }

        if (!hash_equals($configuredToken, $presentedToken)) {
            return $this->htmlMessage('Scam report failed', 'The form token is invalid.', Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function extractRequestValue(Request $request, string $key): string
    {
        $value = $request->request->get($key, $request->query->get($key, ''));

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param list<string> $addresses
     * @param list<string> $invalidAddresses
     * @param list<array{address:string,network:string,label:string,action:string}> $results
     */
    private function logFormSubmission(
        Request $request,
        string $status,
        string $rawSubmission,
        array $addresses,
        array $invalidAddresses,
        array $results,
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        try {
            $this->auditLogger->logFormSubmission($request, $status, $rawSubmission, $addresses, $invalidAddresses, $results);
        } catch (\Throwable $exception) {
            error_log('Unable to write scam report audit log: '.$exception->getMessage());
        }
    }

    private function htmlForm(string $token, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $escapedToken = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedTokenUrl = htmlspecialchars(rawurlencode($token), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedError = $error === null ? '' : htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $errorBlock = $escapedError === '' ? '' : sprintf('<p class="error">%s</p>', $escapedError);

        $html = strtr(<<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Report scam addresses</title>
  <style>
    :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
    body { align-items: center; background: #f8fafc; color: #111827; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
    main { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 18px 45px rgba(15, 23, 42, .08); max-width: 760px; padding: 28px; width: 100%; }
    h1 { font-size: 24px; line-height: 1.2; margin: 0 0 10px; }
    p { color: #374151; font-size: 15px; line-height: 1.5; margin: 0 0 18px; }
    label { color: #111827; display: block; font-size: 14px; font-weight: 700; margin: 18px 0 8px; }
    textarea { border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; color: #111827; font: inherit; width: 100%; }
    textarea { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; min-height: 180px; padding: 12px; resize: vertical; }
    button { background: #b91c1c; border: 0; border-radius: 6px; color: #fff; cursor: pointer; font-size: 15px; font-weight: 700; margin-top: 20px; padding: 12px 16px; }
    .error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #7f1d1d; margin-bottom: 16px; padding: 12px; overflow-wrap: anywhere; }
  </style>
</head>
<body>
  <main>
    <h1>Report scam addresses</h1>
    <p>Paste one or more Stellar account addresses. Each submitted address will be marked as scam.</p>
    {{ error_block }}
    <form method="post" action="/v1/scam-reports/form?token={{ token_url }}">
      <input type="hidden" name="token" value="{{ token }}">
      <label for="addresses">Addresses</label>
      <textarea id="addresses" name="addresses" autocomplete="off" spellcheck="false"></textarea>
      <button type="submit">Mark as scam</button>
    </form>
  </main>
</body>
</html>
HTML, [
            '{{ token }}' => $escapedToken,
            '{{ token_url }}' => $escapedTokenUrl,
            '{{ error_block }}' => $errorBlock,
        ]);

        return $this->htmlResponse($html, $status);
    }

    /**
     * @param list<array{address:string,network:string,label:string,action:string}> $results
     */
    private function htmlResults(array $results, string $token): Response
    {
        $items = '';
        $escapedTokenUrl = htmlspecialchars(rawurlencode($token), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        foreach ($results as $result) {
            $items .= sprintf(
                '<li><code>%s</code><span>%s</span></li>',
                htmlspecialchars($result['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($result['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        $html = strtr(<<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Scam report accepted</title>
  <style>
    :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
    body { align-items: center; background: #f8fafc; color: #111827; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
    main { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 18px 45px rgba(15, 23, 42, .08); max-width: 760px; padding: 28px; width: 100%; }
    h1 { font-size: 24px; line-height: 1.2; margin: 0 0 12px; }
    p { color: #374151; font-size: 16px; line-height: 1.55; margin: 0 0 18px; }
    ul { display: grid; gap: 10px; list-style: none; margin: 0; padding: 0; }
    li { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #7f1d1d; display: grid; gap: 8px; padding: 12px; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; overflow-wrap: anywhere; }
    span { color: #991b1b; font-size: 13px; font-weight: 700; text-transform: uppercase; }
    a { background: #b91c1c; border-radius: 6px; color: #fff; display: inline-block; font-size: 15px; font-weight: 700; margin-top: 20px; padding: 12px 16px; text-decoration: none; }
  </style>
</head>
<body>
  <main>
    <h1>Scam report accepted</h1>
    <p>The submitted addresses were marked as scam.</p>
    <ul>{{ items }}</ul>
    <a href="/v1/scam-reports/form?token={{ token_url }}">Report more addresses</a>
  </main>
</body>
</html>
HTML, [
            '{{ items }}' => $items,
            '{{ token_url }}' => $escapedTokenUrl,
        ]);

        return $this->htmlResponse($html, Response::HTTP_OK);
    }

    private function htmlMessage(string $title, string $message, int $status, ?string $address = null): Response
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedAddress = $address === null ? null : htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $addressBlock = $escapedAddress === null ? '' : sprintf('<p class="address">%s</p>', $escapedAddress);

        $html = strtr(<<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ title }}</title>
  <style>
    :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
    body { align-items: center; background: #f8fafc; color: #111827; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
    main { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 18px 45px rgba(15, 23, 42, .08); max-width: 680px; padding: 28px; width: 100%; }
    h1 { font-size: 24px; line-height: 1.2; margin: 0 0 12px; }
    p { color: #374151; font-size: 16px; line-height: 1.55; margin: 0; }
    .address { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #7f1d1d; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; margin-top: 18px; overflow-wrap: anywhere; padding: 12px; }
  </style>
</head>
<body>
  <main>
    <h1>{{ title }}</h1>
    <p>{{ message }}</p>
    {{ address_block }}
  </main>
</body>
</html>
HTML, [
            '{{ title }}' => $escapedTitle,
            '{{ message }}' => $escapedMessage,
            '{{ address_block }}' => $addressBlock,
        ]);

        return $this->htmlResponse($html, $status);
    }

    private function htmlResponse(string $html, int $status): Response
    {
        $response = new Response($html, $status);

        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'status' => $status,
        ], $status);
    }
}
