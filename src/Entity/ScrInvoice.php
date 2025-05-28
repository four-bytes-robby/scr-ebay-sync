<?php
// src/Entity/ScrInvoice.php
namespace Four\ScrEbaySync\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrInvoiceRepository;

#[ORM\Entity(repositoryClass: ScrInvoiceRepository::class)]
#[ORM\Table(name: "scr_invoices")]
class ScrInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 20)]
    private string $seller;

    #[ORM\Column(type: "string", length: 12)]
    private string $invoiceno;

    #[ORM\Column(type: "integer")]
    private int $customer_id;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $receivedat = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $paydat = null;

    #[ORM\Column(type: "string", length: 10)]
    private string $paymethod;

    #[ORM\Column(type: "string", length: 255)]
    private string $payment_id;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $invoicedat = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $printdat = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $dispatchdat = null;

    #[ORM\Column(type: "float")]
    private float $postage;

    #[ORM\Column(type: "text")]
    private string $comment;

    #[ORM\Column(type: "text")]
    private string $info;

    #[ORM\Column(type: "smallint")]
    private int $dealervat;

    #[ORM\Column(type: "integer")]
    private int $closed;

    #[ORM\Column(type: "string", length: 255)]
    private string $tracking;

    #[ORM\Column(type: "bigint")]
    private string $ebayId;

    #[ORM\Column(type: "bigint")]
    private string $ebayIdSandbox;

    #[ORM\Column(type: "string", length: 255)]
    private string $important;

    #[ORM\Column(type: "integer")]
    private int $processing;

    #[ORM\Column(type: "integer")]
    private int $confirmationsent;

    #[ORM\Column(type: "integer")]
    private int $sevdeskid;

    #[ORM\Column(type: "string", length: 36)]
    private string $lexoffice_id;

    #[ORM\Column(type: "string", length: 10)]
    private string $source;

    #[ORM\Column(type: "string", length: 36)]
    private string $source_id;

    #[ORM\Column(type: "string", length: 255)]
    private string $source_user;

    #[ORM\Column(type: "string", length: 255)]
    private string $shipper;

    #[ORM\Column(type: "decimal", precision: 5, scale: 2, nullable: true)]
    private ?string $paymentfee = null;

    #[ORM\Column(type: "datetime")]
    public DateTime $updated;

    // Getter and setter methods
    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getSeller(): string
    {
        return $this->seller;
    }

    public function setSeller(string $seller): self
    {
        $this->seller = $seller;
        return $this;
    }

    public function getInvoiceno(): string
    {
        return $this->invoiceno;
    }

    public function setInvoiceno(string $invoiceno): self
    {
        $this->invoiceno = $invoiceno;
        return $this;
    }

    public function getCustomerId(): int
    {
        return $this->customer_id;
    }

    public function setCustomerId(int $customer_id): self
    {
        $this->customer_id = $customer_id;
        return $this;
    }

    public function getReceivedat(): ?DateTime
    {
        return $this->receivedat;
    }

    public function setReceivedat(?DateTime $receivedat): self
    {
        $this->receivedat = $receivedat;
        return $this;
    }

    public function getPaydat(): ?DateTime
    {
        return $this->paydat;
    }

    public function setPaydat(?DateTime $paydat): self
    {
        $this->paydat = $paydat;
        return $this;
    }

    public function getPaymethod(): string
    {
        return $this->paymethod;
    }

    public function setPaymethod(string $paymethod): self
    {
        $this->paymethod = $paymethod;
        return $this;
    }

    public function getPaymentId(): string
    {
        return $this->payment_id;
    }

    public function setPaymentId(string $payment_id): self
    {
        $this->payment_id = $payment_id;
        return $this;
    }

    public function getInvoicedat(): ?DateTime
    {
        return $this->invoicedat;
    }

    public function setInvoicedat(?DateTime $invoicedat): self
    {
        $this->invoicedat = $invoicedat;
        return $this;
    }

    public function getPrintdat(): ?DateTime
    {
        return $this->printdat;
    }

    public function setPrintdat(?DateTime $printdat): self
    {
        $this->printdat = $printdat;
        return $this;
    }

    public function getDispatchdat(): ?DateTime
    {
        return $this->dispatchdat;
    }

    public function setDispatchdat(?DateTime $dispatchdat): self
    {
        $this->dispatchdat = $dispatchdat;
        return $this;
    }

    public function getPostage(): float
    {
        return $this->postage;
    }

    public function setPostage(float $postage): self
    {
        $this->postage = $postage;
        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function getInfo(): string
    {
        return $this->info;
    }

    public function setInfo(string $info): self
    {
        $this->info = $info;
        return $this;
    }

    public function getDealervat(): int
    {
        return $this->dealervat;
    }

    public function setDealervat(int $dealervat): self
    {
        $this->dealervat = $dealervat;
        return $this;
    }

    public function getClosed(): int
    {
        return $this->closed;
    }

    public function setClosed(int $closed): self
    {
        $this->closed = $closed;
        return $this;
    }

    public function getTracking(): string
    {
        return $this->tracking;
    }

    public function setTracking(string $tracking): self
    {
        $this->tracking = $tracking;
        return $this;
    }

    public function getEbayId(): string
    {
        return $this->ebayId;
    }

    public function setEbayId(string $ebayId): self
    {
        $this->ebayId = $ebayId;
        return $this;
    }

    public function getEbayIdSandbox(): string
    {
        return $this->ebayIdSandbox;
    }

    public function setEbayIdSandbox(string $ebayIdSandbox): self
    {
        $this->ebayIdSandbox = $ebayIdSandbox;
        return $this;
    }

    public function getImportant(): string
    {
        return $this->important;
    }

    public function setImportant(string $important): self
    {






































































































































































































































































        $this->important = $important;
        return $this;
    }

    public function getProcessing(): int
    {
        return $this->processing;
    }

    public function setProcessing(int $processing): self
    {
        $this->processing = $processing;
        return $this;
    }

    public function getConfirmationsent(): int
    {
        return $this->confirmationsent;
    }

    public function setConfirmationsent(int $confirmationsent): self
    {
        $this->confirmationsent = $confirmationsent;
        return $this;
    }

    public function getSevdeskid(): int
    {
        return $this->sevdeskid;
    }

    public function setSevdeskid(int $sevdeskid): self
    {
        $this->sevdeskid = $sevdeskid;
        return $this;
    }

    public function getLexofficeId(): string
    {
        return $this->lexoffice_id;
    }

    public function setLexofficeId(string $lexoffice_id): self
    {
        $this->lexoffice_id = $lexoffice_id;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getSourceId(): string
    {
        return $this->source_id;
    }

    public function setSourceId(string $source_id): self
    {
        $this->source_id = $source_id;
        return $this;
    }

    public function getSourceUser(): string
    {
        return $this->source_user;
    }

    public function setSourceUser(string $source_user): self
    {
        $this->source_user = $source_user;
        return $this;
    }

    public function getShipper(): string
    {
        return $this->shipper ?? '';
    }

    public function setShipper(string $shipper): self
    {
        $this->shipper = $shipper;
        return $this;
    }

    public function getPaymentfee(): ?string
    {
        return $this->paymentfee;
    }

    public function setPaymentfee(?string $paymentfee): self
    {
        $this->paymentfee = $paymentfee;
        return $this;
    }
    
    /**
     * Check if the invoice is paid
     *
     * @return bool Whether the invoice is paid
     */
    public function isPaid(): bool
    {
        return $this->paydat !== null;
    }
    
    /**
     * Check if the invoice is shipped
     *
     * @return bool Whether the invoice is shipped
     */
    public function isShipped(): bool
    {
        return $this->dispatchdat !== null;
    }
    
    /**
     * Check if the invoice is closed
     *
     * @return bool Whether the invoice is closed
     */
    public function isClosed(): bool
    {
        return $this->closed == 1;
    }
    
    /**
     * Get the total value of the invoice (including positions)
     *
     * @return float The total value
     */
    public function getTotal(): float
    {
        // This would typically load invoice positions and sum them
        // For now, we'll just return a placeholder implementation
        return 0.0;
    }
}