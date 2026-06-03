<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class ScamReportAuditLogger
{
    public function __construct(
        private readonly string $logFile,
    ) {
    }

    /**
     * @param list<string> $addresses
     * @param list<string> $invalidAddresses
     * @param list<array{address:string,network:string,label:string,action:string}> $results
     */
    public function logFormSubmission(
        Request $request,
        string $status,
        string $rawSubmission,
        array $addresses,
        array $invalidAddresses,
        array $results,
    ): void {
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create scam report audit log directory: %s', $directory));
        }

        $payload = [
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'event' => 'scam_report_form_submission',
            'status' => $status,
            'ip' => $request->getClientIp() ?? 'unknown',
            'user_agent' => (string) $request->headers->get('User-Agent', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'raw_submission' => $rawSubmission,
            'addresses' => $addresses,
            'invalid_addresses' => $invalidAddresses,
            'results' => $results,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write scam report audit log: %s', $this->logFile));
        }
    }
}
