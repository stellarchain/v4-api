<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Sep55ContractVerificationService
{
    private const GITHUB_API_BASE_URL = 'https://api.github.com';
    private const GITHUB_WEB_BASE_URL = 'https://github.com';
    private const USER_AGENT = 'Stellarchain-SEP55-Verification/1.0';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{
     *   isVerified:bool,
     *   sourceRepo:?string,
     *   githubAddress:?string,
     *   attestationUrl:?string,
     *   commitHash:?string,
     *   error:?string
     * }
     */
    public function verifyFromWasm(string $wasmSha256, string $wasmBytes): array
    {
        $normalizedHash = strtolower(trim($wasmSha256));
        if (preg_match('/^[0-9a-f]{64}$/', $normalizedHash) !== 1) {
            return $this->failure('Invalid WASM SHA256 hash.', null, null, null);
        }

        $sourceRepo = $this->extractSourceRepoFromWasm($wasmBytes);
        if ($sourceRepo === null) {
            return $this->failure('Missing source_repo metadata in contractmetav0.', null, null, null);
        }

        $repo = $this->parseGithubRepoFromSourceRepo($sourceRepo);
        if ($repo === null) {
            return $this->failure('source_repo is not a supported GitHub repository format.', $sourceRepo, null, null);
        }

        $attestationUrl = sprintf('%s/%s/%s/attestations', self::GITHUB_WEB_BASE_URL, $repo['owner'], $repo['repo']);
        $attestation = $this->fetchFirstAttestation($repo['owner'], $repo['repo'], $normalizedHash);
        if ($attestation['ok'] !== true) {
            return $this->failure($attestation['error'], $sourceRepo, $repo['githubAddress'], $attestationUrl);
        }

        $statement = $attestation['statement'];
        $subjectHash = $this->extractSubjectSha256($statement);
        if ($subjectHash === null || $subjectHash !== $normalizedHash) {
            return $this->failure(
                'Attestation subject digest does not match deployed WASM hash.',
                $sourceRepo,
                $repo['githubAddress'],
                $attestationUrl
            );
        }

        $resolvedDependency = $this->extractResolvedDependency($statement);
        if ($resolvedDependency === null) {
            return $this->failure(
                'Attestation predicate.buildDefinition.resolvedDependencies[0] is missing.',
                $sourceRepo,
                $repo['githubAddress'],
                $attestationUrl
            );
        }

        $dependencyRepo = $this->parseGithubRepoFromDependencyUri((string) ($resolvedDependency['uri'] ?? ''));
        if ($dependencyRepo === null || $dependencyRepo !== $repo['repoKey']) {
            return $this->failure(
                'Attestation dependency URI does not match source_repo metadata.',
                $sourceRepo,
                $repo['githubAddress'],
                $attestationUrl
            );
        }

        $commitHash = null;
        $digest = $resolvedDependency['digest'] ?? null;
        if (is_array($digest)) {
            $candidate = strtolower(trim((string) ($digest['gitCommit'] ?? '')));
            if (preg_match('/^[0-9a-f]{7,64}$/', $candidate) === 1) {
                $commitHash = $candidate;
            }
        }

        return [
            'isVerified' => true,
            'sourceRepo' => $sourceRepo,
            'githubAddress' => $repo['githubAddress'],
            'attestationUrl' => $attestationUrl,
            'commitHash' => $commitHash,
            'error' => null,
        ];
    }

    public function extractSourceRepoFromWasm(string $wasmBytes): ?string
    {
        $entries = $this->extractWasmMetadataEntries($wasmBytes);
        foreach ($entries as $entry) {
            $key = strtolower(trim((string) ($entry['key'] ?? '')));
            if ($key !== 'source_repo') {
                continue;
            }

            $value = trim((string) ($entry['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   ok:bool,
     *   statement?:array<string,mixed>,
     *   error:string
     * }
     */
    private function fetchFirstAttestation(string $owner, string $repo, string $wasmSha256): array
    {
        $url = sprintf('%s/repos/%s/%s/attestations/sha256:%s', self::GITHUB_API_BASE_URL, $owner, $repo, $wasmSha256);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => self::USER_AGENT,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'timeout' => 15.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 404) {
                return ['ok' => false, 'error' => 'No GitHub attestation found for this WASM hash.'];
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                return ['ok' => false, 'error' => sprintf('GitHub attestation API returned status %d.', $statusCode)];
            }

            $decoded = $response->toArray(false);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'GitHub attestation response is not valid JSON.'];
            }

            $attestations = $decoded['attestations'] ?? null;
            if (!is_array($attestations) || $attestations === []) {
                return ['ok' => false, 'error' => 'GitHub attestation list is empty.'];
            }

            $first = $attestations[0] ?? null;
            if (!is_array($first)) {
                return ['ok' => false, 'error' => 'Invalid attestation payload structure.'];
            }

            $encodedPayload = $first['bundle']['dsseEnvelope']['payload'] ?? null;
            if (!is_string($encodedPayload) || trim($encodedPayload) === '') {
                return ['ok' => false, 'error' => 'Attestation payload is missing.'];
            }

            $payloadRaw = base64_decode($encodedPayload, true);
            if (!is_string($payloadRaw) || trim($payloadRaw) === '') {
                return ['ok' => false, 'error' => 'Attestation payload base64 decoding failed.'];
            }

            $statement = json_decode($payloadRaw, true);
            if (!is_array($statement)) {
                return ['ok' => false, 'error' => 'Attestation payload JSON decoding failed.'];
            }

            return [
                'ok' => true,
                'statement' => $statement,
                'error' => '',
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => sprintf('GitHub attestation request failed: %s', $exception->getMessage()),
            ];
        }
    }

    /**
     * @param array<string,mixed> $statement
     */
    private function extractSubjectSha256(array $statement): ?string
    {
        $subject = $statement['subject'] ?? null;
        if (!is_array($subject)) {
            return null;
        }

        foreach ($subject as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $digest = $entry['digest'] ?? null;
            if (!is_array($digest)) {
                continue;
            }

            $sha256 = strtolower(trim((string) ($digest['sha256'] ?? '')));
            if (preg_match('/^[0-9a-f]{64}$/', $sha256) === 1) {
                return $sha256;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $statement
     * @return array<string,mixed>|null
     */
    private function extractResolvedDependency(array $statement): ?array
    {
        $predicate = $statement['predicate'] ?? null;
        if (!is_array($predicate)) {
            return null;
        }

        $buildDefinition = $predicate['buildDefinition'] ?? null;
        if (!is_array($buildDefinition)) {
            return null;
        }

        $resolvedDependencies = $buildDefinition['resolvedDependencies'] ?? null;
        if (!is_array($resolvedDependencies) || $resolvedDependencies === []) {
            return null;
        }

        $first = $resolvedDependencies[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    private function extractWasmMetadataEntries(string $wasmBytes): array
    {
        $entries = [];
        if (strlen($wasmBytes) < 8 || substr($wasmBytes, 0, 4) !== "\x00asm") {
            return $entries;
        }

        $offset = 8;
        $size = strlen($wasmBytes);
        while ($offset < $size) {
            $sectionId = ord($wasmBytes[$offset]);
            $offset++;

            $sectionSize = $this->readLeb128Unsigned($wasmBytes, $offset, $size);
            if ($sectionSize === null) {
                return $entries;
            }

            $sectionStart = $offset;
            $sectionEnd = $sectionStart + $sectionSize;
            if ($sectionEnd > $size) {
                return $entries;
            }

            if ($sectionId !== 0) {
                $offset = $sectionEnd;
                continue;
            }

            $nameLength = $this->readLeb128Unsigned($wasmBytes, $offset, $sectionEnd);
            if ($nameLength === null || $offset + $nameLength > $sectionEnd) {
                $offset = $sectionEnd;
                continue;
            }

            $sectionName = substr($wasmBytes, $offset, $nameLength);
            $offset += $nameLength;

            if ($sectionName !== 'contractmetav0' && $sectionName !== 'meta') {
                $offset = $sectionEnd;
                continue;
            }

            $sectionData = substr($wasmBytes, $offset, $sectionEnd - $offset);
            $entries = array_merge($entries, $this->parseScMetaEntries($sectionData));
            $offset = $sectionEnd;
        }

        return $entries;
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    private function parseScMetaEntries(string $payload): array
    {
        $entries = [];
        if (strlen($payload) < 4) {
            return $entries;
        }

        $offset = 0;
        $count = $this->readUint32Be($payload, $offset);
        if ($count === null) {
            return $entries;
        }

        for ($i = 0; $i < $count; $i++) {
            $discriminant = $this->readUint32Be($payload, $offset);
            if ($discriminant === null) {
                break;
            }
            if ($discriminant !== 0) {
                continue;
            }

            $key = $this->readXdrString($payload, $offset);
            $value = $this->readXdrString($payload, $offset);
            if ($key === null || $value === null) {
                break;
            }

            $entries[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $entries;
    }

    private function readLeb128Unsigned(string $value, int &$offset, int $limit): ?int
    {
        $result = 0;
        $shift = 0;

        for ($index = 0; $index < 5; $index++) {
            if ($offset >= $limit) {
                return null;
            }

            $byte = ord($value[$offset]);
            $offset++;
            $result |= (($byte & 0x7f) << $shift);

            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }

        return null;
    }

    private function readUint32Be(string $value, int &$offset): ?int
    {
        if ($offset + 4 > strlen($value)) {
            return null;
        }

        $chunk = substr($value, $offset, 4);
        $offset += 4;
        $unpacked = unpack('Nvalue', $chunk);

        return is_array($unpacked) ? (int) $unpacked['value'] : null;
    }

    private function readXdrString(string $value, int &$offset): ?string
    {
        $length = $this->readUint32Be($value, $offset);
        if ($length === null || $length < 0 || $offset + $length > strlen($value)) {
            return null;
        }

        $decoded = substr($value, $offset, $length);
        $offset += $length;

        $padding = (4 - ($length % 4)) % 4;
        if ($offset + $padding > strlen($value)) {
            return null;
        }
        $offset += $padding;

        return $decoded;
    }

    /**
     * @return array{
     *   owner:string,
     *   repo:string,
     *   repoKey:string,
     *   githubAddress:string
     * }|null
     */
    private function parseGithubRepoFromSourceRepo(string $sourceRepo): ?array
    {
        $normalized = trim($sourceRepo);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with(strtolower($normalized), 'github:')) {
            $normalized = substr($normalized, 7);
        } elseif (preg_match('~^(?:https?://|git\+https?://)?(?:www\.)?github\.com/(.+)$~i', $normalized, $matches) === 1) {
            $normalized = (string) ($matches[1] ?? '');
        } else {
            return null;
        }

        $normalized = trim($normalized, " \t\n\r\0\x0B/");
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('~\.git$~i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('~@.+$~', '', $normalized) ?? $normalized;

        $parts = explode('/', $normalized);
        if (count($parts) < 2) {
            return null;
        }

        $owner = trim((string) ($parts[0] ?? ''));
        $repo = trim((string) ($parts[1] ?? ''));
        if ($owner === '' || $repo === '') {
            return null;
        }

        $owner = preg_replace('~[^A-Za-z0-9_.-]~', '', $owner) ?? '';
        $repo = preg_replace('~[^A-Za-z0-9_.-]~', '', $repo) ?? '';
        if ($owner === '' || $repo === '') {
            return null;
        }

        return [
            'owner' => $owner,
            'repo' => $repo,
            'repoKey' => strtolower($owner . '/' . $repo),
            'githubAddress' => sprintf('%s/%s/%s', self::GITHUB_WEB_BASE_URL, $owner, $repo),
        ];
    }

    private function parseGithubRepoFromDependencyUri(string $uri): ?string
    {
        $candidate = trim($uri);
        if ($candidate === '') {
            return null;
        }

        if (preg_match('~pkg:github/([^/\s]+)/([^@\s?#/]+)~i', $candidate, $matches) === 1) {
            return strtolower($matches[1] . '/' . preg_replace('~\.git$~i', '', $matches[2]));
        }

        if (preg_match('~github\.com[:/]([^/\s]+)/([^@\s?#/]+)~i', $candidate, $matches) === 1) {
            return strtolower($matches[1] . '/' . preg_replace('~\.git$~i', '', $matches[2]));
        }

        return null;
    }

    /**
     * @return array{
     *   isVerified:bool,
     *   sourceRepo:?string,
     *   githubAddress:?string,
     *   attestationUrl:?string,
     *   commitHash:?string,
     *   error:?string
     * }
     */
    private function failure(
        string $error,
        ?string $sourceRepo,
        ?string $githubAddress,
        ?string $attestationUrl,
    ): array {
        return [
            'isVerified' => false,
            'sourceRepo' => $sourceRepo,
            'githubAddress' => $githubAddress,
            'attestationUrl' => $attestationUrl,
            'commitHash' => null,
            'error' => $error,
        ];
    }
}
