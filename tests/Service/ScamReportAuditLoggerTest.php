<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ScamReportAuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ScamReportAuditLoggerTest extends TestCase
{
    public function testLogFormSubmissionWritesJsonLineWithIpAndSubmission(): void
    {
        $logFile = sys_get_temp_dir().'/scam-report-audit-'.bin2hex(random_bytes(6)).'.log';
        $logger = new ScamReportAuditLogger($logFile);

        $request = Request::create('/v1/scam-reports/form?token=secret-token', 'POST', [
            'token' => 'secret-token',
        ], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'SDF Scam Reporter',
        ]);

        $logger->logFormSubmission(
            $request,
            'accepted',
            'gdfapqos',
            ['GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD'],
            [],
            [[
                'address' => 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD',
                'network' => 'mainnet',
                'label' => 'Scam',
                'action' => 'created',
            ]]
        );

        $line = trim((string) file_get_contents($logFile));
        self::assertNotSame('', $line);

        $payload = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('scam_report_form_submission', $payload['event']);
        self::assertSame('accepted', $payload['status']);
        self::assertSame('203.0.113.9', $payload['ip']);
        self::assertSame('SDF Scam Reporter', $payload['user_agent']);
        self::assertSame('gdfapqos', $payload['raw_submission']);
        self::assertSame(['GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD'], $payload['addresses']);
        self::assertArrayNotHasKey('token', $payload);

        @unlink($logFile);
    }
}
