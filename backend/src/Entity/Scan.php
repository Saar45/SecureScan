<?php

namespace App\Entity;

use App\Repository\ScanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ScanRepository::class)]
#[ORM\Table(name: 'scans')]
class Scan
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'scans')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(name: 'executed_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $executedAt;

    #[ORM\Column(name: 'global_score', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $globalScore = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    /** @var Collection<int, Finding> */
    #[ORM\OneToMany(targetEntity: Finding::class, mappedBy: 'scan', cascade: ['remove'])]
    private Collection $findings;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->executedAt = new \DateTime();
        $this->findings = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getExecutedAt(): \DateTimeInterface
    {
        return $this->executedAt;
    }

    public function getGlobalScore(): ?string
    {
        return $this->globalScore;
    }

    public function setGlobalScore(?string $globalScore): static
    {
        $this->globalScore = $globalScore;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /** @return Collection<int, Finding> */
    public function getFindings(): Collection
    {
        return $this->findings;
    }
}
