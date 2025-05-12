<?php
// src/Services/EbayOrder/OrderItemProcessor.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\ScrInvoicePos;
use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\ScrCountry;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for processing order items from eBay orders
 */
class OrderItemProcessor
{
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    /**
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_order_item_processor');
    }
    
    /**
     * Create invoice positions from order line items
     *
     * @param array $order The order data
     * @param ScrInvoice $invoice The invoice entity
     * @return array Created invoice positions
     */
    public function createInvoicePositions(array $order, ScrInvoice $invoice): array
    {
        $positions = [];
        
        foreach ($order['lineItems'] as $lineItem) {
            // Get SKU from line item
            $sku = $lineItem['sku'] ?? null;
            
            if (!$sku) {
                $this->logger->warning("Line item without SKU in order {$order['orderId']}, skipping");
                continue;
            }
            
            // Get item
            $item = $this->entityManager->getRepository(ScrItem::class)
                ->findOneBy(['id' => $sku]);
            
            if (!$item) {
                $this->logger->warning("Item with SKU {$sku} not found for order {$order['orderId']}, skipping");
                continue;
            }
            
            // Create position
            $position = new ScrInvoicePos();
            
            // Get country for tax calculation
            $countryCode = $order['shippingAddress']['countryCode'];
            $country = $this->entityManager->getRepository(ScrCountry::class)
                ->findOneBy(['ISO2' => $countryCode]);
            
            if (!$country) {
                throw new \RuntimeException("Country with code {$countryCode} not found");
            }
            
            // Determine tax rate
            $taxCategory = $this->determineTaxCategory($item);
            $taxRate = 0;
            
            switch ($taxCategory) {
                case 'Books':
                    $taxRate = $country->getTaxbooks();
                    break;
                case 'Tickets':
                    $taxRate = $country->getTaxtickets();
                    break;
                default:
                    $taxRate = $country->getTaxregular();
            }
            
            // Calculate net price
            $grossPrice = (float)$lineItem['lineItemCost']['value'];
            $taxFactor = (float)$taxRate / 100 + 1;
            $netPrice = $grossPrice / $taxFactor;
            
            // Set position data
            $position->setInvoiceId($invoice->getId());
            $position->setItemId($item->getId());
            $position->setAdditionalOption('');
            $position->setQuantity($lineItem['quantity']);
            $position->setPrice($grossPrice);
            $position->setNetprice($netPrice);
            $position->setProfit($netPrice - $item->getPurchasePrice());
            
            // Save position
            $this->entityManager->persist($position);
            $positions[] = $position;
        }
        
        $this->entityManager->flush();
        
        return $positions;
    }
    
    /**
     * Update item quantities after order creation
     *
     * @param array $order The order data
     */
    public function updateItemQuantities(array $order): void
    {
        foreach ($order['lineItems'] as $lineItem) {
            // Get SKU from line item
            $sku = $lineItem['sku'] ?? null;
            
            if (!$sku) {
                continue;
            }
            
            // Update item quantity
            $qb = $this->entityManager->createQueryBuilder();
            $qb->update(ScrItem::class, 'i')
                ->set('i.quantity', 'i.quantity - :quantity')
                ->set('i.updated', ':now')
                ->where('i.id = :id')
                ->setParameter('quantity', $lineItem['quantity'])
                ->setParameter('now', new \DateTime())
                ->setParameter('id', $sku);
                
            $qb->getQuery()->execute();
        }
    }
    
    /**
     * Determine tax category for an item
     *
     * @param ScrItem $item The item
     * @return string Tax category (Regular, Books, Tickets)
     */
    private function determineTaxCategory(ScrItem $item): string
    {
        $name = $item->getName();
        
        // Extract format
        $format = '';
        if (preg_match('/\(([^\)]+)\)$/', $name, $match)) {
            $format = strtoupper($match[1]);
        }
        
        if (preg_match('/book/i', $format)) {
            return 'Books';
        }
        
        if (preg_match('/ticket/i', $format) || strtolower($item->getGroupId()) === 'tickets') {
            return 'Tickets';
        }
        
        return 'Regular';
    }
}