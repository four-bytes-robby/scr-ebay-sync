<?php
// src/Entity/ScrRegion.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrRegionRepository;

#[ORM\Entity(repositoryClass: ScrRegionRepository::class)]
#[ORM\Table(name: "scr_regions")]
class ScrRegion
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 10)]
    private string $region;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_cd;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_additional_cd;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_lp;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_additional_lp;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_flat;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $shipping_free;

    // Getters and setters
    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getShippingCd(): string
    {
        return $this->shipping_cd;
    }

    public function setShippingCd(string $shipping_cd): self
    {
        $this->shipping_cd = $shipping_cd;
        return $this;
    }

    public function getShippingAdditionalCd(): string
    {
        return $this->shipping_additional_cd;
    }

    public function setShippingAdditionalCd(string $shipping_additional_cd): self
    {
        $this->shipping_additional_cd = $shipping_additional_cd;
        return $this;
    }

    public function getShippingLp(): string
    {
        return $this->shipping_lp;
    }

    public function setShippingLp(string $shipping_lp): self
    {
        $this->shipping_lp = $shipping_lp;
        return $this;
    }

    public function getShippingAdditionalLp(): string
    {
        return $this->shipping_additional_lp;
    }

    public function setShippingAdditionalLp(string $shipping_additional_lp): self
    {
        $this->shipping_additional_lp = $shipping_additional_lp;
        return $this;
    }

    public function getShippingFlat(): string
    {
        return $this->shipping_flat;
    }

    public function setShippingFlat(string $shipping_flat): self
    {
        $this->shipping_flat = $shipping_flat;
        return $this;
    }

    public function getShippingFree(): string
    {
        return $this->shipping_free;
    }

    public function setShippingFree(string $shipping_free): self
    {
        $this->shipping_free = $shipping_free;
        return $this;
    }
}