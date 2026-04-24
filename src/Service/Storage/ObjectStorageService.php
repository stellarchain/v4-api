<?php

namespace App\Service\Storage;

use Aws\S3\S3ClientInterface;

final class ObjectStorageService
{
    public function __construct(
        private readonly S3ClientInterface $s3Client,
        private readonly string $bucket,
        private readonly string $publicBaseUrl,
        private readonly bool $defaultVisibilityPublic,
    ) {
    }

    /**
     * @param array<string,string> $metadata
     */
    public function uploadContent(
        string $key,
        string $content,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): string {
        $normalizedKey = ltrim(trim($key), '/');

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $normalizedKey,
            'Body' => $content,
            'ContentType' => $contentType,
            'Metadata' => $metadata,
        ];

        if ($this->defaultVisibilityPublic) {
            $params['ACL'] = 'public-read';
        }

        $this->s3Client->putObject($params);

        return $this->buildPublicUrl($normalizedKey);
    }

    public function deleteObject(string $key): void
    {
        $normalizedKey = ltrim(trim($key), '/');

        $this->s3Client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $normalizedKey,
        ]);
    }

    public function buildPublicUrl(string $key): string
    {
        $normalizedKey = ltrim(trim($key), '/');

        if ($this->publicBaseUrl !== '') {
            return rtrim($this->publicBaseUrl, '/') . '/' . $normalizedKey;
        }

        return $this->s3Client->getObjectUrl($this->bucket, $normalizedKey);
    }
}
