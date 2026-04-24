<?php

namespace App\Entity;

use App\Repository\ContractSourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContractSourceRepository::class)]
#[ORM\Table(name: 'contract_sources')]
#[ORM\UniqueConstraint(name: 'uniq_contract_sources_wasm_id', columns: ['wasm_id'])]
class ContractSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'wasm_id', length: 64)]
    private string $wasmId;

    #[ORM\Column(name: 'source_code', type: 'text', nullable: true)]
    private ?string $sourceCode = null;

    #[ORM\Column(name: 'source_code_sha256', length: 64, nullable: true)]
    private ?string $sourceCodeSha256 = null;

    #[ORM\Column(name: 'wasm_blob', type: 'blob', nullable: true)]
    private $wasmBlob = null;

    #[ORM\Column(name: 'wasm_blob_sha256', length: 64, nullable: true)]
    private ?string $wasmBlobSha256 = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $status = 0;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'decompiled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decompiledAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWasmId(): string
    {
        return $this->wasmId;
    }

    public function setWasmId(string $wasmId): self
    {
        $this->wasmId = $wasmId;

        return $this;
    }

    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }

    public function setSourceCode(?string $sourceCode): self
    {
        $this->sourceCode = $sourceCode;

        return $this;
    }

    public function getSourceCodeSha256(): ?string
    {
        return $this->sourceCodeSha256;
    }

    public function setSourceCodeSha256(?string $sourceCodeSha256): self
    {
        $this->sourceCodeSha256 = $sourceCodeSha256;

        return $this;
    }

    public function getWasmBlob(): ?string
    {
        if ($this->wasmBlob === null) {
            return null;
        }

        if (is_resource($this->wasmBlob)) {
            $content = stream_get_contents($this->wasmBlob);
            return is_string($content) ? $content : null;
        }

        return is_string($this->wasmBlob) ? $this->wasmBlob : null;
    }

    public function setWasmBlob(?string $wasmBlob): self
    {
        $this->wasmBlob = $wasmBlob;

        return $this;
    }

    public function getWasmBlobSha256(): ?string
    {
        return $this->wasmBlobSha256;
    }

    public function setWasmBlobSha256(?string $wasmBlobSha256): self
    {
        $this->wasmBlobSha256 = $wasmBlobSha256;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getDecompiledAt(): ?\DateTimeImmutable
    {
        return $this->decompiledAt;
    }

    public function setDecompiledAt(?\DateTimeImmutable $decompiledAt): self
    {
        $this->decompiledAt = $decompiledAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
