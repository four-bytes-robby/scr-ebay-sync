<?php
// src/Entity/EbayTransaction.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\EbayTransactionRepository;

#[ORM\Entity(repositoryClass: EbayTransactionRepository::class)]
#[ORM\Table(name: "ebay_transactions")]
class EbayTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 255)]
    private string $ebayTransactionId;

    #[ORM\Column(type: "datetime")]
    private \DateTime $ebayCreated;

    #[ORM\Column(type: "string", length: 255)]
    private string $ebayOrderId;

    #[ORM\Column(type: "string", length: 255)]
    private string $ebayOrderLineItemId;

    #[ORM\Column(type: "string", length: 255)]
    private string $ebayItemId;

    #[ORM\Column(type: "string", length: 255)]
    private string $ebayBuyerId;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $ebayFinalValueFee;

    #[ORM\Column(type: "string", length: 255)]
    private string $ebayTracking;

    #[ORM\Column(type: "integer")]
    private int $invoice_id;

    #[ORM\Column(type: "integer")]
    private int $quantity;

    #[ORM\Column(type: "string", length: 20)]
    private string $item_id;

    #[ORM\Column(type: "integer")]
    private int $paid;

    #[ORM\Column(type: "integer")]
    private int $shipped;

    #[ORM\Column(type: "integer")]
    private int $canceled;

    #[ORM\Column(type: "datetime")]
    private \DateTime $created;

    #[ORM\Column(type: "datetime")]
    private \DateTime $updated;

    // Getters and setters
    public function getEbayTransactionId(): string
    {
        return $this->ebayTransactionId;
    }

    public function setEbayTransactionId(string $ebayTransactionId): self
    {
        $this->ebayTransactionId = $ebayTransactionId;
        return $this;
    }

    public function getEbayCreated(): \DateTime
    {
        return $this->ebayCreated;
    }

    public function setEbayCreated(\DateTime $ebayCreated): self
    {
        $this->ebayCreated = $ebayCreated;
        return $this;
    }

    public function getEbayOrderId(): string
    {
        return $this->ebayOrderId;
    }

    public function setEbayOrderId(string $ebayOrderId): self
    {
        $this->ebayOrderId = $ebayOrderId;
        return $this;
    }

    public function getEbayOrderLineItemId(): string
    {
        return $this->ebayOrderLineItemId;
    }

    public function setEbayOrderLineItemId(string $ebayOrderLineItemId): self
    {
        $this->ebayOrderLineItemId = $ebayOrderLineItemId;
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

    public function getEbayBuyerId(): string
    {
        return $this->ebayBuyerId;
    }

    public function setEbayBuyerId(string $ebayBuyerId): self
    {
        $this->ebayBuyerId = $ebayBuyerId;
        return $this;
    }

    public function getEbayFinalValueFee(): string
    {
        return $this->ebayFinalValueFee;
    }

    public function setEbayFinalValueFee(string $ebayFinalValueFee): self
    {
        $this->ebayFinalValueFee = $ebayFinalValueFee;
        return $this;
    }

    public function getEbayTracking(): string
    {
        return $this->ebayTracking;
    }

    public function setEbayTracking(string $ebayTracking): self
    {
        $this->ebayTracking = $ebayTracking;
        return $this;
    }

    public function getInvoiceId(): int
    {
        return $this->invoice_id;
    }

    public function setInvoiceId(int $invoice_id): self
    {
        $this->invoice_id = $invoice_id;
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

    public function getItemId(): string
    {
        return $this->item_id;
    }

    public function setItemId(string $item_id): self
    {
        $this->item_id = $item_id;
        return $this;
    }

    public function getPaid(): int
    {
        return $this->paid;
    }

    public function setPaid(int $paid): self
    {
        $this->paid = $paid;
        return $this;
    }

    public function getShipped(): int
    {
        return $this->shipped;
    }

    public function setShipped(int $shipped): self
    {
        $this->shipped = $shipped;
        return $this;
    }

    public function getCanceled(): int
    {
        return $this->canceled;
    }

    public function setCanceled(int $canceled): self
    {
        $this->canceled = $canceled;
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
}