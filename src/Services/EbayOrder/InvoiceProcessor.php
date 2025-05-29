<?php
// src/Services/EbayOrder/InvoiceProcessor.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use DateTime;
use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\ScrCustomer;
use Four\ScrEbaySync\Entity\ScrCountry;
use Four\ScrEbaySync\Entity\ScrTexte;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for processing invoices from eBay orders
 */
class InvoiceProcessor
{
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    // Message templates
    private string $germanInfoTemplate = 'Danke für Deine eBay Bestellung (ebay-Name {UserName} / Bestel-Nr. {OrderId}).' . PHP_EOL . PHP_EOL . 
        'Tipp: In unserem Online-Shop unter SupremeChaos.com gibt es bessere Preise (keine ebay-Gebühren!), geringere Versandkosten und die Bestellungen werden schneller bearbeitet!';
    
    private string $englishInfoTemplate = 'Thank you for your eBay order (ebay name {UserName} / order no {OrderId}).' . PHP_EOL . PHP_EOL .
        'Tip: If ordered directly in our online shop at SupremeChaos.com you can benefit from better prices (no ebay fees!), lower postage and faster processing!';
    
    /**
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_invoice_processor');
    }
    
    /**
     * Create an invoice from order data
     *
     * @param array $order The order data
     * @param ScrCustomer $customer The customer entity
     * @return ScrInvoice The created invoice
     */
    public function createInvoice(array $order, ScrCustomer $customer): ScrInvoice
    {
        $invoice = new ScrInvoice();
        
        // Set invoice number based on year + sequence
        $year = new DateTime()->format('Y');
        $nextId = $this->getNextInvoiceId($year);
        $invoice->setId($nextId);
        
        // Basic fields
        $invoice->setCustomerId($customer->getId());
        $invoice->setSeller('SCR');
        $invoice->setInvoiceno('');
        $invoice->setLexofficeId('');
        
        // Dates
        $receivedat = new DateTime($order['creationDate']);
        $invoice->setReceivedat($receivedat);

        $paydat = null;
        if (isset($order['paymentSummary']['payments'][0]['paymentDate'])) {
            $paydat = new DateTime($order['paymentSummary']['payments'][0]['paymentDate']);
        }
        $invoice->setPaydat($paydat);
        
        // Payment method
        $invoice->setPaymethod('ebay');
        $invoice->setPaymentId($order['orderId']);
        
        // Comments
        $isGerman = in_array($customer->getCountry(), ['DEU', 'AUT', 'CHE']);
        
        if ($isGerman) {
            $comment = $this->germanInfoTemplate;
        } else {
            $comment = $this->englishInfoTemplate;
        }
        
        $comment = str_replace('{UserName}', $order['buyer']['username'], $comment);
        $comment = str_replace('{OrderId}', $order['orderId'], $comment);
        $invoice->setComment($comment);
        
        // Standard info text
        $standardInfo = $this->entityManager->getRepository(ScrTexte::class)
            ->findOneBy(['varName' => 'StandardInfodeutsch']);
        
        $invoice->setInfo($standardInfo ? $standardInfo->getTxtText() : '');
        
        // Buyer message
        $important = '';
        if (!empty($order['buyerCheckoutNotes'])) {
            $important = $this->shortenString($order['buyerCheckoutNotes'], 250, '[.. > ebay]');
        }
        $invoice->setImportant($important);
        
        // Shipping info
        $invoice->setTracking('');
        $invoice->setShipper('');
        
        // eBay info
        $invoice->setEbayId(0);
        $invoice->setEbayIdSandbox(0);
        $invoice->setSource('ebay');
        $invoice->setSourceId($order['orderId']);
        $invoice->setSourceUser($order['buyer']['username']);
        
        // Status fields
        $invoice->setClosed(0);
        $invoice->setProcessing(0);
        $invoice->setConfirmationsent(0);
        $invoice->setSevdeskid(0);
        $invoice->setDealervat(0);
        
        // Calculate international fees
        $paymentFee = 0.35; // Standard fee for fixed price listings
        
        // Calculate total value for percentage fees
        $totalWithTaxes = (float)$order['pricingSummary']['total']['value'];
        
        // Add additional international fees
        $paymentFee += $this->calculateInternationalFees($customer->getCountry(), $totalWithTaxes);
        $invoice->setPaymentfee($paymentFee);
        
        // Shipping costs
        $shippingCost = 0;
        if (isset($order['pricingSummary']['deliveryCost']['value'])) {
            $shippingCost = (float)$order['pricingSummary']['deliveryCost']['value'];
        }
        if (isset($order['pricingSummary']['deliveryDiscount']['value'])) {
            $shippingCost = (float)$order['pricingSummary']['deliveryCost']['value'];
        }
        $invoice->setPostage($shippingCost);

        // Updated
        $invoice->setUpdated(new DateTime());
        
        // Save invoice
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
        
        return $invoice;
    }
    
    /**
     * Get next invoice ID based on year
     *
     * @param int $year Year
     * @return int Next ID
     */
    private function getNextInvoiceId(int $year): int
    {
        $minId = $year * 100000;
        $maxId = ($year + 1) * 100000 - 1;
        
        $qb = $this->entityManager->createQueryBuilder();
        $maxCurrentId = $qb->select('MAX(i.id)')
            ->from(ScrInvoice::class, 'i')
            ->where('i.id >= :minId')
            ->andWhere('i.id <= :maxId')
            ->setParameter('minId', $minId)
            ->setParameter('maxId', $maxId)
            ->getQuery()
            ->getSingleScalarResult();
            
        return $maxCurrentId ? $maxCurrentId + 1 : $minId + 1;
    }
    
    /**
     * Calculate international fees
     *
     * @param string $countryCode ISO3 country code
     * @param float $totalWithTaxes Total order value
     * @return float Fee amount
     */
    private function calculateInternationalFees(string $countryCode, float $totalWithTaxes): float
    {
        // Convert ISO3 to ISO2
        $country = $this->entityManager->getRepository(ScrCountry::class)
            ->findOneBy(['ISO3' => $countryCode]);
            
        if (!$country) {
            return 0;
        }
        
        $countryCode = $country->getISO2();
        
        // Eurozone countries
        $eurozoneCountryCodes = ['AT', 'BE', 'DE', 'EE', 'FI', 'FR', 'GR', 'IE', 'IT', 'HR', 'LU', 'LV', 'LT', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES', 'CY'];
        
        // Eurozone and Sweden - no fee
        if (in_array($countryCode, $eurozoneCountryCodes) || $countryCode === 'SE') {
            return 0;
        }
        
        // UK - 1.2%
        if ($countryCode === 'GB') {
            return 0.012 * $totalWithTaxes;
        }
        
        // Europe (except Eurozone, Sweden, UK), USA and Canada - 1.6%
        $europeCountryCodes = ['AL', 'AD', 'AZ', 'BA', 'BG', 'DK', 'FO', 'GE', 'IS', 'KZ', 'LI', 'MC', 'MD', 'ME', 'MK', 'NO', 'PL', 'RO', 'RU', 'SM', 'CH', 'RS', 'SJ', 'CZ', 'TR', 'HU', 'UA', 'VA', 'BY'];
        
        if (in_array($countryCode, $europeCountryCodes) || $countryCode === 'US' || $countryCode === 'CA') {
            return 0.016 * $totalWithTaxes;
        }
        
        // All others - 3.3%
        return 0.033 * $totalWithTaxes;
    }
    
    /**
     * Shorten a string to a maximum length
     *
     * @param string $string The string to shorten
     * @param int $maxLength Maximum length
     * @param string $suffix Suffix to append if shortened
     * @return string The shortened string
     */
    private function shortenString(string $string, int $maxLength, string $suffix = ''): string
    {
        if (mb_strlen($string) <= $maxLength) {
            return $string;
        }
        
        return mb_substr($string, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }
}