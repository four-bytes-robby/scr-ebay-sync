<?php
// src/Entity/ScrInvoicePos.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrInvoicePosRepository;

#[ORM\Entity(repositoryClass: ScrInvoicePosRepository::class)]
#[ORM\Table(name: "scr_invoicepos")]
class ScrInvoicePos
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "integer")]
    private int $invoice_id;

    #[ORM\Column(type: "string", length: 20)]
    private string $item_id;

    #[ORM\Column(type: "string", length: 255)]
    private string $additional_option;

    #[ORM\Column(type: "integer")]
    private int $quantity;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $netprice;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $profit;

    // Getters and setters
    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
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

    public function getItemId(): string
    {
        return $this->item_id;
    }

    public function setItemId(string $item_id): self
    {
        $this->item_id = $item_id;
        return $this;
    }

    public function getAdditionalOption(): string
    {
        return $this->additional_option;
    }

    public function setAdditionalOption(string $additional_option): self
    {
        $this->additional_option = $additional_option;
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

    public function getNetprice(): string
    {
        return $this->netprice;
    }

    public function setNetprice(string $netprice): self
    {
        $this->netprice = $netprice;
        return $this;
    }

    public function getProfit(): string
    {
        return $this->profit;
    }

    public function setProfit(string $profit): self
    {
        $this->profit = $profit;
        return $this;
    }
}