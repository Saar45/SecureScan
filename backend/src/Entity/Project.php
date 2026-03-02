<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'repository_url', type: 'string', length: 500)]
    private string $repositoryUrl;

    #[ORM\Column(name: 'main_branch', type: 'string', length: 100)]
    private string $mainBranch = 'main';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    /** @var Collection<int, Scan> */
    #[ORM\OneToMany(targetEntity: Scan::class, mappedBy: 'project', cascade: ['remove'])]
    private Collection $scans;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTime();
        $this->scans = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getRepositoryUrl(): string
    {
        return $this->repositoryUrl;
    }

    public function setRepositoryUrl(string $repositoryUrl): static
    {
        $this->repositoryUrl = $repositoryUrl;
        return $this;
    }

    public function getMainBranch(): string
    {
        return $this->mainBranch;
    }

    public function setMainBranch(string $mainBranch): static
    {
        $this->mainBranch = $mainBranch;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Scan> */
    public function getScans(): Collection
    {
        return $this->scans;
    }
}
