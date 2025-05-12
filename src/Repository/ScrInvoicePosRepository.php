<?php
// src/Repository/ScrInvoicePosRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrInvoicePos;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrInvoicePos entity
 */
class ScrInvoicePosRepository extends EntityRepository
{
    /**
     * Find invoice positions by invoice ID
     *
     * @param int $invoiceId The invoice ID
     * @return array The invoice positions
     */
    public function findByInvoiceId(int $invoiceId): array
    {
        return $this->findBy(['invoice_id' => $invoiceId]);
    }
    
    /**
     * Find invoice positions by item ID
     *
     * @param string $itemId The item ID
     * @return array The invoice positions
     */
    public function findByItemId(string $itemId): array
    {
        return $this->findBy(['item_id' => $itemId]);
    }
    
    /**
     * Calculate total amount for an invoice
     *
     * @param int $invoiceId The invoice ID
     * @return float The total amount
     */
    public function calculateInvoiceTotal(int $invoiceId): float
    {
        $qb = $this->createQueryBuilder('p');
        $qb->select('SUM(p.price * p.quantity)')
            ->where('p.invoice_id = :invoiceId')
            ->setParameter('invoiceId', $invoiceId);
            
        return (float)$qb->getQuery()->getSingleScalarResult();
    }
    
    /**
     * Calculate total profit for an invoice
     *
     * @param int $invoiceId The invoice ID
     * @return float The total profit
     */
    public function calculateInvoiceProfit(int $invoiceId): float
    {
        $qb = $this->createQueryBuilder('p');
        $qb->select('SUM(p.profit * p.quantity)')
            ->where('p.invoice_id = :invoiceId')
            ->setParameter('invoiceId', $invoiceId);
            
        return (float)$qb->getQuery()->getSingleScalarResult();
    }
    
    /**
     * Get total quantity sold for an item
     *
     * @param string $itemId The item ID
     * @return int The total quantity sold
     */
    public function getTotalQuantitySold(string $itemId): int
    {
        $qb = $this->createQueryBuilder('p');
        $qb->select('SUM(p.quantity)')
            ->where('p.item_id = :itemId')
            ->setParameter('itemId', $itemId);
            
        return (int)$qb->getQuery()->getSingleScalarResult();
    }
}