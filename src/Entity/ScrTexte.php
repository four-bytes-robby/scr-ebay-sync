<?php
// src/Entity/ScrTexte.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrTexteRepository;

#[ORM\Entity(repositoryClass: ScrTexteRepository::class)]
#[ORM\Table(name: "scr_texte")]
class ScrTexte
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 50)]
    private string $varName;

    #[ORM\Column(type: "text")]
    private string $txtText;

    // Getters and setters
    public function getVarName(): string
    {
        return $this->varName;
    }

    public function setVarName(string $varName): self
    {
        $this->varName = $varName;
        return $this;
    }

    public function getTxtText(): string
    {
        return $this->txtText;
    }

    public function setTxtText(string $txtText): self
    {
        $this->txtText = $txtText;
        return $this;
    }
}