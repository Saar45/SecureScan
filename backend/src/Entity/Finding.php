<?php

namespace App\Entity;

use App\Repository\FindingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FindingRepository::class)]
#[ORM\Table(name: 'findings')]
class Finding
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Scan::class, inversedBy: 'findings')]
    #[ORM\JoinColumn(name: 'scan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Scan $scan;

    #[ORM\Column(name: 'tool_source', type: 'string', length: 100)]
    private string $toolSource;

    #[ORM\Column(type: 'string', length: 50)]
    private string $severity;

    #[ORM\Column(name: 'owasp_category', type: 'string', length: 10, nullable: true)]
    private ?string $owaspCategory = null;

    #[ORM\Column(name: 'file_path', type: 'string', length: 500)]
    private string $filePath;

    #[ORM\Column(name: 'line_number', type: Types::INTEGER, nullable: true)]
    private ?int $lineNumber = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(name: 'raw_code', type: Types::TEXT, nullable: true)]
    private ?string $rawCode = null;

    #[ORM\OneToOne(targetEntity: Remediation::class, mappedBy: 'finding', cascade: ['remove'])]
    private ?Remediation $remediation = null;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getScan(): Scan
    {
        return $this->scan;
    }

    public function setScan(Scan $scan): static
    {
        $this->scan = $scan;
        return $this;
    }

    public function getToolSource(): string
    {
        return $this->toolSource;
    }

    public function setToolSource(string $toolSource): static
    {
        $this->toolSource = $toolSource;
        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;
        return $this;
    }

    public function getOwaspCategory(): ?string
    {
        return $this->owaspCategory;
    }

    public function setOwaspCategory(?string $owaspCategory): static
    {
        $this->owaspCategory = $owaspCategory;
        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getLineNumber(): ?int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(?int $lineNumber): static
    {
        $this->lineNumber = $lineNumber;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getRawCode(): ?string
    {
        return $this->rawCode;
    }

    public function setRawCode(?string $rawCode): static
    {
        $this->rawCode = $rawCode;
        return $this;
    }

    public function getRemediation(): ?Remediation
    {
        return $this->remediation;
    }

    public function setRemediation(?Remediation $remediation): static
    {
        $this->remediation = $remediation;
        return $this;
    }
}
