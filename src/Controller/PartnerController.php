<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Partners\PartnerCatalog;
use App\Service\Partners\PartnerCatalogConflict;
use App\Service\Partners\PartnerClickCounter;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PartnerController
{
    public function __construct(
        private readonly PartnerCatalog $catalog,
        private readonly PartnerClickCounter $clicks,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:app.empty_string:PARTNER_ADMIN_TOKEN)%')]
        private readonly string $adminToken,
    ) {
    }

    #[Route('/v1/partners', name: 'partners_catalog', methods: ['GET'])]
    public function index(): Response
    {
        try {
            return new JsonResponse(['categories' => PartnerCatalog::CATEGORIES, 'entries' => $this->catalog->published()], headers: ['Cache-Control' => 'public, max-age=60']);
        } catch (\Throwable $failure) {
            return $this->unavailable($failure);
        }
    }

    #[Route('/v1/partner-clicks', name: 'partner_clicks_store', methods: ['POST'])]
    public function click(Request $request): Response
    {
        try {
            $payload = $this->payload($request, 256);
            if (array_diff(array_keys($payload), ['partnerId', 'placement', 'consent']) !== []
                || ($payload['consent'] ?? null) !== true
                || !is_string($payload['partnerId'] ?? null)
                || !in_array($payload['placement'] ?? null, ['directory', 'category'], true)
                || $request->headers->get('DNT') === '1'
                || $request->headers->get('Sec-GPC') === '1') {
                throw new InvalidArgumentException('A valid catalog activation and optional-cookie consent are required.');
            }
            if (!$this->catalog->hasPublishedEntry($payload['partnerId'])) {
                return $this->error('partner_not_found', 'Partner is unavailable.', 404);
            }
            $this->clicks->record($payload['partnerId'], $payload['placement']);

            return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException|JsonException $exception) {
            return $this->error('invalid_click', $exception->getMessage(), 422);
        } catch (\Throwable $failure) {
            return $this->unavailable($failure);
        }
    }

    #[Route('/v1/admin/partners', name: 'admin_partners_catalog', methods: ['GET'])]
    public function admin(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }
        try {
            return $this->adminResponse($this->catalog->read());
        } catch (\Throwable $failure) {
            return $this->unavailable($failure);
        }
    }

    #[Route('/v1/admin/partners', name: 'admin_partners_replace', methods: ['PUT'])]
    public function replace(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }
        $revision = $request->headers->get('If-Match', '');
        if (!preg_match('/^"([a-f0-9]{64})"$/D', $revision, $matches)) {
            return $this->error('revision_required', 'Reload the catalog before saving.', 428);
        }
        try {
            $payload = $this->payload($request, 600000);

            return $this->adminResponse($this->catalog->replace($payload['entries'] ?? null, $matches[1]));
        } catch (PartnerCatalogConflict $exception) {
            return $this->error('catalog_changed', $exception->getMessage(), 412);
        } catch (InvalidArgumentException|JsonException $exception) {
            return $this->error('invalid_catalog', $exception->getMessage(), 422);
        } catch (\Throwable $failure) {
            return $this->unavailable($failure);
        }
    }

    #[Route('/v1/admin/partner-clicks.csv', name: 'admin_partner_clicks_csv', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }
        try {
            $from = $request->query->get('from', '');
            $to = $request->query->get('to', '');
            $rows = $this->clicks->report($from, $to);
            $csv = "day_utc,partner_id,placement,clicks\r\n";
            // Values are validated slugs, fixed placement keys, UTC dates and integers.
            foreach ($rows as $row) {
                $csv .= implode(',', [$row['day'], $row['partnerId'], $row['placement'], $row['clicks']])."\r\n";
            }

            return new Response($csv, headers: [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="partner-clicks-'.$from.'_'.$to.'.csv"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->error('invalid_range', $exception->getMessage(), 422);
        } catch (\Throwable $failure) {
            return $this->unavailable($failure);
        }
    }

    private function authorize(Request $request): ?Response
    {
        if (strlen($this->adminToken) < 32) {
            return $this->error('admin_unavailable', 'Partner administration is not configured.', 503);
        }
        $authorization = $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ') || !hash_equals($this->adminToken, substr($authorization, 7))) {
            $response = $this->error('unauthorized', 'Enter a valid partner administration token.', 401);
            $response->headers->set('WWW-Authenticate', 'Bearer');

            return $response;
        }

        return null;
    }

    private function payload(Request $request, int $limit): array
    {
        $body = $request->getContent();
        if (strlen($body) > $limit || $request->getContentTypeFormat() !== 'json') {
            throw new InvalidArgumentException('Send a bounded JSON request body.');
        }
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        return $payload;
    }

    private function adminResponse(array $entries): Response
    {
        $revision = $this->catalog->revision($entries);

        return new JsonResponse(['categories' => PartnerCatalog::CATEGORIES, 'entries' => $entries, 'revision' => $revision], headers: [
            'Cache-Control' => 'private, no-store',
            'ETag' => '"'.$revision.'"',
        ]);
    }

    private function unavailable(\Throwable $failure): Response
    {
        $this->logger->error('Partner catalog or click storage unavailable.', ['exception' => $failure]);

        return $this->error('partners_unavailable', 'Partner service is temporarily unavailable. Please retry.', 503);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }
}
