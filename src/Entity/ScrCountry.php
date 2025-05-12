<?php
// src/Entity/ScrCountry.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrCountryRepository;

#[ORM\Entity(repositoryClass: ScrCountryRepository::class)]
#[ORM\Table(name: "scr_countries")]
class ScrCountry
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 2)]
    private string $ISO2;

    #[ORM\Column(type: "string", length: 3)]
    private string $ISO3;

    #[ORM\Column(type: "string", length: 100)]
    private string $english;

    #[ORM\Column(type: "string", length: 100)]
    private string $deutsch;

    #[ORM\Column(type: "string", length: 10)]
    private string $callingcode;

    #[ORM\Column(type: "string", length: 10)]
    private string $region;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $taxregular;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $taxbooks;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $taxtickets;

    // Getters and setters
    public function getISO2(): string
    {
        return $this->ISO2;
    }

    public function setISO2(string $ISO2): self
    {
        $this->ISO2 = $ISO2;
        return $this;
    }

    public function getISO3(): string
    {
        return $this->ISO3;
    }

    public function setISO3(string $ISO3): self
    {
        $this->ISO3 = $ISO3;
        return $this;
    }

    public function getEnglish(): string
    {
        return $this->english;
    }

    public function setEnglish(string $english): self
    {
        $this->english = $english;
        return $this;
    }

    public function getDeutsch(): string
    {
        return $this->deutsch;
    }

    public function setDeutsch(string $deutsch): self
    {
        $this->deutsch = $deutsch;
        return $this;
    }

    public function getCallingcode(): string
    {
        return $this->callingcode;
    }

    public function setCallingcode(string $callingcode): self
    {
        $this->callingcode = $callingcode;
        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getTaxregular(): string
    {
        return $this->taxregular;
    }

    public function setTaxregular(string $taxregular): self
    {
        $this->taxregular = $taxregular;
        return $this;
    }

    public function getTaxbooks(): string
    {
        return $this->taxbooks;
    }

    public function setTaxbooks(string $taxbooks): self
    {
        $this->taxbooks = $taxbooks;
        return $this;
    }

    public function getTaxtickets(): string
    {
        return $this->taxtickets;
    }

    public function setTaxtickets(string $taxtickets): self
    {
        $this->taxtickets = $taxtickets;
        return $this;
    }
}