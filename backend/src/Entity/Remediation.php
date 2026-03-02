<?php

namespace App\Entity;

use App\Repository\RemediationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RemediationRepository::class)]
#[ORM\Table(name: 'remediations')]
class Remediation
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Finding::class, inversedBy: 'remediation')]
    #[ORM\JoinColumn(name: 'finding_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(name: 'proposed_fix', type: Types::TEXT, nullable: true)]
    private ?string $proposedFix = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    #[ORM\Column(name: 'git_branch_name', type: 'string', length: 255, nullable: true)]
    private ?string $gitBranchName = null;

    #[ORM\Column(name: 'pr_url', type: 'string', length: 500, nullable: true)]
    private ?string $prUrl = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getFinding(): Finding
    {
        return $this->finding;
    }

    public function setFinding(Finding $finding): static
    {
        $this->finding = $finding;
        return $this;
    }

    public function getProposedFix(): ?string
    {
        return $this->proposedFix;
    }

    public function setProposedFix(?string $proposedFix): static
    {
        $this->proposedFix = $proposedFix;
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

    public function getGitBranchName(): ?string
    {
        return $this->gitBranchName;
    }

    public function setGitBranchName(?string $gitBranchName): static
    {
        $this->gitBranchName = $gitBranchName;
        return $this;
    }

    public function getPrUrl(): ?string
    {
        return $this->prUrl;
    }

    public function setPrUrl(?string $prUrl): static
    {
        $this->prUrl = $prUrl;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
