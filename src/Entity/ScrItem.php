<?php
// src/Entity/ScrItem.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrItemRepository;

#[ORM\Entity(repositoryClass: ScrItemRepository::class)]
#[ORM\Table(name: "scr_items")]
class ScrItem
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 20)]
    private string $id;

    #[ORM\Column(type: "string", length: 20)]
    private string $group_id;

    #[ORM\Column(type: "datetime")]
    private \DateTime $available_from;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTime $available_until = null;

    #[ORM\Column(type: "string", length: 255)]
    private string $name;

    #[ORM\Column(type: "string", length: 30)]
    private string $genre;

    #[ORM\Column(type: "text")]
    private string $english;

    #[ORM\Column(type: "text")]
    private string $deutsch;

    #[ORM\Column(type: "float")]
    private float $price;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2)]
    private string $purchase_price;

    #[ORM\Column(type: "float")]
    private float $wholesaleprice;

    #[ORM\Column(type: "integer")]
    private int $quantity;

    #[ORM\Column(type: "integer")]
    private int $quantity_purchased;

    #[ORM\Column(type: "bigint")]
    private string $ean;

    #[ORM\Column(type: "string", length: 255)]
    private string $label;

    #[ORM\Column(type: "date", nullable: true)]
    private ?\DateTime $releasedate = null;

    #[ORM\Column(type: "string", length: 50)]
    private string $property;

    #[ORM\Column(type: "string", length: 250)]
    private string $property_option;

    #[ORM\Column(type: "string", length: 30)]
    private string $catno;

    #[ORM\Column(type: "integer")]
    private int $ebay;

    #[ORM\Column(type: "smallint")]
    private int $shopware_active;

    #[ORM\Column(type: "string", length: 36)]
    private string $shopware_id;

    #[ORM\Column(type: "string", length: 3)]
    private string $image_sizes;

    #[ORM\Column(type: "bigint")]
    private string $bandcamp_id;

    #[ORM\Column(type: "bigint")]
    private string $discogs_id;

    #[ORM\Column(type: "bigint")]
    private string $bigcartel_id;

    #[ORM\Column(type: "datetime")]
    private \DateTime $updated;

    /**
     * OneToOne nullable inverse relationship to EbayItem
     * Mapped by the 'scrItem' property in EbayItem
     * The inverse side does NOT need JoinColumn - it's handled by the owning side
     */
    #[ORM\OneToOne(targetEntity: EbayItem::class, mappedBy: "scrItem")]
    private ?EbayItem $ebayItem = null;

    // Getter und Setter Methoden
    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getGroupId(): string
    {
        return $this->group_id;
    }

    public function setGroupId(string $group_id): self
    {
        $this->group_id = $group_id;
        return $this;
    }

    public function getAvailableFrom(): \DateTime
    {
        return $this->available_from;
    }

    public function setAvailableFrom(\DateTime $available_from): self
    {
        $this->available_from = $available_from;
        return $this;
    }

    public function getAvailableUntil(): ?\DateTime
    {
        return $this->available_until;
    }

    public function setAvailableUntil(?\DateTime $available_until): self
    {
        $this->available_until = $available_until;
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

    public function getGenre(): string
    {
        return $this->genre;
    }

    public function setGenre(string $genre): self
    {
        $this->genre = $genre;
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

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getPurchasePrice(): string
    {
        return $this->purchase_price;
    }

    public function setPurchasePrice(string $purchase_price): self
    {
        $this->purchase_price = $purchase_price;
        return $this;
    }

    public function getWholesaleprice(): float
    {
        return $this->wholesaleprice;
    }

    public function setWholesaleprice(float $wholesaleprice): self
    {
        $this->wholesaleprice = $wholesaleprice;
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

    public function getQuantityPurchased(): int
    {
        return $this->quantity_purchased;
    }

    public function setQuantityPurchased(int $quantity_purchased): self
    {
        $this->quantity_purchased = $quantity_purchased;
        return $this;
    }

    public function getEan(): string
    {
        return $this->ean;
    }

    public function setEan(string $ean): self
    {
        $this->ean = $ean;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getReleasedate(): ?\DateTime
    {
        return $this->releasedate;
    }

    public function setReleasedate(?\DateTime $releasedate): self
    {
        $this->releasedate = $releasedate;
        return $this;
    }

    public function getProperty(): string
    {
        return $this->property;
    }

    public function setProperty(string $property): self
    {
        $this->property = $property;
        return $this;
    }

    public function getPropertyOption(): string
    {
        return $this->property_option;
    }

    public function setPropertyOption(string $property_option): self
    {
        $this->property_option = $property_option;
        return $this;
    }

    public function getCatno(): string
    {
        return $this->catno;
    }

    public function setCatno(string $catno): self
    {
        $this->catno = $catno;
        return $this;
    }

    public function getEbay(): int
    {
        return $this->ebay;
    }

    public function setEbay(int $ebay): self
    {
        $this->ebay = $ebay;
        return $this;
    }

    public function getShopwareActive(): int
    {
        return $this->shopware_active;
    }

    public function setShopwareActive(int $shopware_active): self
    {
        $this->shopware_active = $shopware_active;
        return $this;
    }

    public function getShopwareId(): string
    {
        return $this->shopware_id;
    }

    public function setShopwareId(string $shopware_id): self
    {
        $this->shopware_id = $shopware_id;
        return $this;
    }

    public function getImageSizes(): string
    {
        return $this->image_sizes;
    }

    public function setImageSizes(string $image_sizes): self
    {
        $this->image_sizes = $image_sizes;
        return $this;
    }

    public function getBandcampId(): string
    {
        return $this->bandcamp_id;
    }

    public function setBandcampId(string $bandcamp_id): self
    {
        $this->bandcamp_id = $bandcamp_id;
        return $this;
    }

    public function getDiscogsId(): string
    {
        return $this->discogs_id;
    }

    public function setDiscogsId(string $discogs_id): self
    {
        $this->discogs_id = $discogs_id;
        return $this;
    }

    public function getBigcartelId(): string
    {
        return $this->bigcartel_id;
    }

    public function setBigcartelId(string $bigcartel_id): self
    {
        $this->bigcartel_id = $bigcartel_id;
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

    public function getEbayItem(): ?EbayItem
    {
        return $this->ebayItem;
    }

    public function setEbayItem(?EbayItem $ebayItem): self
    {
        $this->ebayItem = $ebayItem;
        return $this;
    }

    public function getEbayItemId() : ?string
    {
        return $this->ebayItem?->getEbayItemId();
    }
}