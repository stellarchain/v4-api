<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PartnerController;
use App\Service\Partners\PartnerCatalog;
use App\Service\Partners\PartnerClickCounter;
use App\Service\Partners\PartnerFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PartnerControllerTest extends TestCase
{
    private string $directory;
    private PartnerController $controller;
    private PartnerCatalog $catalog;
    private PartnerClickCounter $counter;
    private const TOKEN = 'test-only-partner-token-at-least-32-characters';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/partner-test-'.bin2hex(random_bytes(8));
        $storage = new PartnerFileStorage($this->directory);
        $this->catalog = new PartnerCatalog($storage, dirname(__DIR__, 2).'/resources/partners.json');
        $this->counter = new PartnerClickCounter($storage);
        $this->controller = new PartnerController($this->catalog, $this->counter, new NullLogger(), self::TOKEN);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testPublicCatalogCoversEveryCategoryWithoutInventedCommercialRelationships(): void
    {
        $response = $this->controller->index();
        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertCount(6, $body['entries']);
        self::assertEqualsCanonicalizing(array_keys(PartnerCatalog::CATEGORIES), array_column($body['entries'], 'category'));
        foreach ($body['entries'] as $entry) {
            self::assertSame($entry['id'] === 'sevio' ? 'integration' : 'directory', $entry['relationship']);
            self::assertNull($entry['trackingUrl']);
        }
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testAdminReadWriteAndExportAreProtectedAndDisabledWithoutStrongConfiguration(): void
    {
        $request = Request::create('/v1/admin/partners');
        self::assertSame(401, $this->controller->admin($request)->getStatusCode());
        self::assertSame(401, $this->controller->replace($request)->getStatusCode());
        self::assertSame(401, $this->controller->export($request)->getStatusCode());
        $disabled = new PartnerController($this->catalog, $this->counter, new NullLogger(), '');
        self::assertSame(503, $disabled->admin($this->adminRequest())->getStatusCode());
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testPublishUpdateHideAndOrderingArePersistentAndRejectStaleWrites(): void
    {
        $entries = $this->catalog->read();
        $revision = $this->catalog->revision($entries);
        $entries[0]['status'] = 'inactive';
        $entries[1]['position'] = 999;
        $entries[2]['relationship'] = 'affiliate';
        $entries[2]['trackingUrl'] = 'https://example.com/referral?campaign=stellar';
        $response = $this->controller->replace($this->adminRequest(['entries' => $entries], $revision));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertNotSame($revision, $this->body($response)['revision']);
        $public = $this->body($this->controller->index())['entries'];
        self::assertNotContains('freighter', array_column($public, 'id'));
        self::assertSame('stellarx', $public[count($public) - 1]['id']);
        self::assertSame(412, $this->controller->replace($this->adminRequest(['entries' => []], $revision))->getStatusCode());
        self::assertCount(6, $this->catalog->read());
        self::assertSame(428, $this->controller->replace($this->adminRequest(['entries' => []]))->getStatusCode());
    }

    public function testUnsafeDestinationsDuplicateIdsAndOversizedCatalogsAreRejected(): void
    {
        $original = $this->catalog->read();
        $revision = $this->catalog->revision($original);
        foreach (['javascript:alert(1)', 'http://example.com', 'https://user:password@example.com', "https://example.com/\n"] as $url) {
            $entries = $original;
            $entries[0]['websiteUrl'] = $url;
            self::assertSame(422, $this->controller->replace($this->adminRequest(['entries' => $entries], $revision))->getStatusCode());
        }
        $entries = $original;
        $entries[] = $entries[0];
        self::assertSame(422, $this->controller->replace($this->adminRequest(['entries' => $entries], $revision))->getStatusCode());
        self::assertSame(422, $this->controller->replace($this->adminRequest(['entries' => array_fill(0, 101, $original[0])], $revision))->getStatusCode());
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testClicksRequireConsentAndAllowlistedDimensionsWithoutSensitivePayloads(): void
    {
        $valid = ['partnerId' => 'freighter', 'placement' => 'directory', 'consent' => true];
        foreach ([array_replace($valid, ['consent' => false]), $valid + ['address' => 'sensitive'], array_replace($valid, ['placement' => '/account/private'])] as $payload) {
            self::assertSame(422, $this->controller->click($this->clickRequest($payload))->getStatusCode());
        }
        $dnt = $this->clickRequest($valid);
        $dnt->headers->set('DNT', '1');
        self::assertSame(422, $this->controller->click($dnt)->getStatusCode());
        $gpc = $this->clickRequest($valid);
        $gpc->headers->set('Sec-GPC', '1');
        self::assertSame(422, $this->controller->click($gpc)->getStatusCode());
        self::assertSame(404, $this->controller->click($this->clickRequest(array_replace($valid, ['partnerId' => 'unknown'])))->getStatusCode());
        self::assertDirectoryDoesNotExist($this->directory);
        self::assertSame(204, $this->controller->click($this->clickRequest($valid))->getStatusCode());
        self::assertSame(204, $this->controller->click($this->clickRequest($valid))->getStatusCode());
        $day = gmdate('Y-m-d');
        self::assertSame([['day' => $day, 'partnerId' => 'freighter', 'placement' => 'directory', 'clicks' => 2]], $this->counter->report($day, $day));
        $contents = file_get_contents($this->directory.'/clicks-'.$day.'.json');
        self::assertStringNotContainsString('203.0.113.1', $contents);
        self::assertStringNotContainsString('user-agent', $contents);
        self::assertStringNotContainsString('https://', $contents);
    }

    public function testCsvHasUtcAggregatesAndRejectsInvalidOrUnboundedRanges(): void
    {
        $this->counter->record('freighter', 'directory');
        $day = gmdate('Y-m-d');
        $request = $this->adminRequest();
        $request->query->set('from', $day);
        $request->query->set('to', $day);
        $response = $this->controller->export($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame("day_utc,partner_id,placement,clicks\r\n".$day.",freighter,directory,1\r\n", $response->getContent());
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        foreach ([['2026-02-30', $day], ['2026-10-19', '2026-10-18'], ['2026-01-01', '2026-04-01'], ['../../etc/passwd', $day]] as [$from, $to]) {
            $request->query->set('from', $from);
            $request->query->set('to', $to);
            self::assertSame(422, $this->controller->export($request)->getStatusCode());
        }
    }

    public function testStorageContentionAndCorruptionFailClosed(): void
    {
        $storage = new PartnerFileStorage($this->directory);
        $lock = $storage->lock('clicks-'.gmdate('Y-m-d'));
        try {
            self::assertSame(503, $this->controller->click($this->clickRequest(['partnerId' => 'freighter', 'placement' => 'directory', 'consent' => true]))->getStatusCode());
        } finally {
            $storage->unlock($lock);
        }
        file_put_contents($this->directory.'/catalog.json', '{invalid');
        self::assertSame(503, $this->controller->index()->getStatusCode());
        self::assertStringNotContainsString($this->directory, $this->controller->index()->getContent());
    }

    private function body(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function adminRequest(?array $payload = null, ?string $revision = null): Request
    {
        $request = Request::create('/v1/admin/partners', $payload === null ? 'GET' : 'PUT', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        if ($revision !== null) {
            $request->headers->set('If-Match', '"'.$revision.'"');
        }

        return $request;
    }

    private function clickRequest(array $payload): Request
    {
        return Request::create('/v1/partner-clicks', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.1'], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
