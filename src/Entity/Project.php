<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\SerializedName;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\UniqueConstraint(name: 'uniq_projects_source_url', columns: ['source_url'])]
#[ORM\Index(name: 'idx_projects_name', columns: ['name'])]
#[ORM\Index(name: 'idx_projects_updated_at', columns: ['updated_at'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/projects',
            openapi: new OpenApiOperation(tags: ['Projects'])
        ),
        new Get(
            uriTemplate: '/projects/{id}',
            openapi: new OpenApiOperation(tags: ['Projects'])
        ),
    ],
    order: ['id' => 'DESC'],
    cacheHeaders: [
        'max_age' => 3600,
        'shared_max_age' => 3600,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    paginationEnabled: true,
    paginationItemsPerPage: 20,
    paginationMaximumItemsPerPage: 100,
    paginationClientItemsPerPage: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'category' => 'partial', 'sourceUrl' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['id', 'name', 'updatedAt', 'createdAt', 'totalAwardedAmount', 'roundsCount'])]
final class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'source_url', length: 255, unique: true)]
    #[SerializedName('url')]
    private string $sourceUrl = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $github = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rounds = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $submissions = [];

    #[ORM\Column(name: 'team_size', nullable: true)]
    private ?int $teamSize = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'total_awarded', length: 64, nullable: true)]
    private ?string $totalAwarded = null;

    #[ORM\Column(name: 'total_awarded_amount', type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $totalAwardedAmount = null;

    #[ORM\Column(name: 'rounds_count', options: ['default' => 0])]
    private int $roundsCount = 0;

    #[ORM\Column(name: 'awarded_submissions', nullable: true)]
    private ?int $awardedSubmissions = null;

    #[ORM\Column(name: 'image_alt', length: 255, nullable: true)]
    private ?string $imageAlt = null;

    #[ORM\Column(name: 'image_source_url', type: Types::TEXT, nullable: true)]
    private ?string $imageSourceUrl = null;

    #[ORM\Column(name: 'image_source_file', length: 255, nullable: true)]
    private ?string $imageSourceFile = null;

    #[ORM\Column(name: 'image_storage_key', length: 255, nullable: true)]
    private ?string $imageStorageKey = null;

    #[ORM\Column(name: 'image_public_url', type: Types::TEXT, nullable: true)]
    private ?string $imagePublicUrl = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }

    public function getGithub(): ?string
    {
        return $this->github;
    }

    public function setGithub(?string $github): self
    {
        $this->github = $github;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRounds(): array
    {
        return is_array($this->rounds) ? $this->rounds : [];
    }

    /**
     * @param list<string> $rounds
     */
    public function setRounds(array $rounds): self
    {
        $this->rounds = $rounds;

        return $this;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getSubmissions(): array
    {
        return is_array($this->submissions) ? $this->submissions : [];
    }

    /**
     * @param list<array<string,mixed>> $submissions
     */
    public function setSubmissions(array $submissions): self
    {
        $this->submissions = $submissions;

        return $this;
    }

    public function getTeamSize(): ?int
    {
        return $this->teamSize;
    }

    public function setTeamSize(?int $teamSize): self
    {
        $this->teamSize = $teamSize;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getTotalAwarded(): ?string
    {
        return $this->totalAwarded;
    }

    public function setTotalAwarded(?string $totalAwarded): self
    {
        $this->totalAwarded = $totalAwarded;

        return $this;
    }

    public function getTotalAwardedAmount(): ?string
    {
        return $this->totalAwardedAmount;
    }

    public function setTotalAwardedAmount(?string $totalAwardedAmount): self
    {
        $this->totalAwardedAmount = $totalAwardedAmount;

        return $this;
    }

    public function getRoundsCount(): int
    {
        return $this->roundsCount;
    }

    public function setRoundsCount(int $roundsCount): self
    {
        $this->roundsCount = max(0, $roundsCount);

        return $this;
    }

    public function getAwardedSubmissions(): ?int
    {
        return $this->awardedSubmissions;
    }

    public function setAwardedSubmissions(?int $awardedSubmissions): self
    {
        $this->awardedSubmissions = $awardedSubmissions;

        return $this;
    }

    public function getImageAlt(): ?string
    {
        return $this->imageAlt;
    }

    public function setImageAlt(?string $imageAlt): self
    {
        $this->imageAlt = $imageAlt;

        return $this;
    }

    public function getImageSourceUrl(): ?string
    {
        return $this->imageSourceUrl;
    }

    public function setImageSourceUrl(?string $imageSourceUrl): self
    {
        $this->imageSourceUrl = $imageSourceUrl;

        return $this;
    }

    public function getImageSourceFile(): ?string
    {
        return $this->imageSourceFile;
    }

    public function setImageSourceFile(?string $imageSourceFile): self
    {
        $this->imageSourceFile = $imageSourceFile;

        return $this;
    }

    public function getImageStorageKey(): ?string
    {
        return $this->imageStorageKey;
    }

    public function setImageStorageKey(?string $imageStorageKey): self
    {
        $this->imageStorageKey = $imageStorageKey;

        return $this;
    }

    public function getImagePublicUrl(): ?string
    {
        return $this->imagePublicUrl;
    }

    public function setImagePublicUrl(?string $imagePublicUrl): self
    {
        $this->imagePublicUrl = $imagePublicUrl;

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
