<?php

declare(strict_types=1);

namespace App\Service\ContractTransparency;

final class ContractTransparencyCursor
{
    public function encodeId(int $id): string
    {
        return $this->base64UrlEncode('id:' . max(0, $id));
    }

    public function decodeId(mixed $cursor): ?int
    {
        if (is_int($cursor)) {
            return $cursor > 0 ? $cursor : null;
        }

        if (!is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        $trimmed = trim($cursor);
        if (ctype_digit($trimmed)) {
            $parsed = (int) $trimmed;

            return $parsed > 0 ? $parsed : null;
        }

        $decoded = $this->base64UrlDecode($trimmed);
        if ($decoded === null || !str_starts_with($decoded, 'id:')) {
            return null;
        }

        $id = substr($decoded, 3);
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $parsed = (int) $id;

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param array{ledger:int,rank:int,id:int} $cursor
     */
    public function encodeActivity(array $cursor): string
    {
        return $this->base64UrlEncode(sprintf(
            'activity:%d:%d:%d',
            max(0, $cursor['ledger']),
            max(0, $cursor['rank']),
            max(0, $cursor['id']),
        ));
    }

    /**
     * @return array{ledger:int,rank:int,id:int}|null
     */
    public function decodeActivity(mixed $cursor): ?array
    {
        if (!is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        $decoded = $this->base64UrlDecode(trim($cursor));
        if ($decoded === null) {
            return null;
        }

        if (preg_match('/^activity:([0-9]+):([0-9]+):([0-9]+)$/', $decoded, $matches) !== 1) {
            return null;
        }

        $ledger = (int) $matches[1];
        $rank = (int) $matches[2];
        $id = (int) $matches[3];
        if ($id < 1) {
            return null;
        }

        return [
            'ledger' => $ledger,
            'rank' => $rank,
            'id' => $id,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : null;
    }
}
