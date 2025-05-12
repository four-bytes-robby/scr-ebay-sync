<?php
// src/Entity/EbayItem.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\EbayItemRepository;

#[ORM\Entity(repositoryClass: EbayItemRepository::class)]
#[ORM\Table(name: "ebay_items")]
class EbayItem
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 20)]
    private string $item_id;

    #[ORM\Column(type: "string", length: 13)]
    private string $ebayItemId;

    #[ORM\Column(type: "integer")]
    private int $quantity;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: "datetime")]
    private \DateTime $created;

    #[ORM\Column(type: "datetime")]
    private \DateTime $updated;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTime $deleted = null;

    // Getter und Setter Methoden
    public function getItemId(): string
    {
        return $this->item_id;
    }

    public function setItemId(string $item_id): self
    {
        $this->item_id = $item_id;
        return $this;
    }

    public function getEbayItemId(): string
    {
        return $this->ebayItemId;
    }

    public function setEbayItemId(string $ebayItemId): self
    {
        $this->ebayItemId = $ebayItemId;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getCreated(): \DateTime
    {
        return $this->created;
    }

    public function setCreated(\DateTime $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): \DateTime
    {
        return $this->updated;
    }

    public function setUpdated(\DateTime $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getDeleted(): ?\DateTime
    {
        return $this->deleted;
    }

    public function setDeleted(?\DateTime $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }
}