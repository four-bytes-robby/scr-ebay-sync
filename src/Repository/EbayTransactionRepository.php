<?php
// src/Repository/EbayTransactionRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\EbayTransaction;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for EbayTransaction entity
 */
class EbayTransactionRepository extends EntityRepository
{
    /**
     * Find transaction by eBay order ID
     *
     * @param string $orderId The eBay order ID
     * @return array Transactions for the order
     */
    public function findByOrderId(string $orderId): array
    {
        return $this->findBy(['ebayOrderId' => $orderId]);
    }
    
    /**
     * Find transaction by eBay transaction ID
     *
     * @param string $transactionId The eBay transaction ID
     * @return EbayTransaction|null The transaction or null if not found
     */
    public function findByTransactionId(string $transactionId): ?EbayTransaction
    {
        return $this->find($transactionId);
    }
    
    /**
     * Find transactions by invoice ID
     *
     * @param int $invoiceId The invoice ID
     * @return array Transactions for the invoice
     */
    public function findByInvoiceId(int $invoiceId): array
    {
        return $this->findBy(['invoice_id' => $invoiceId]);
    }
    
    /**
     * Find transactions by item ID
     *
     * @param string $itemId The item ID
     * @return array Transactions for the item
     */
    public function findByItemId(string $itemId): array
    {
        return $this->findBy(['item_id' => $itemId]);
    }
    
    /**
     * Find unpaid transactions
     *
     * @return array Unpaid transactions
     */
    public function findUnpaid(): array
    {
        return $this->findBy(['paid' => 0]);
    }
    
    /**
     * Find unshipped transactions
     *
     * @return array Unshipped transactions
     */
    public function findUnshipped(): array
    {
        return $this->findBy(['paid' => 1, 'shipped' => 0]);
    }
    
    /**
     * Find recent transactions
     *
     * @param int $days Number of days to look back
     * @return array Recent transactions
     */
    public function findRecent(int $days = 30): array
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");
        
        $qb = $this->createQueryBuilder('t');
        $qb->where('t.ebayCreated >= :date')
            ->setParameter('date', $date)
            ->orderBy('t.ebayCreated', 'DESC');
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find transactions with tracking number mismatch
     *
     * @return array Transactions with tracking mismatch
     */
    public function findTrackingMismatch(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join('Four\ScrEbaySync\Entity\ScrInvoice', 'i', 'WITH', 't.invoice_id = i.id')
            ->where('t.ebayTracking != i.tracking')
            ->andWhere('i.tracking IS NOT NULL')
            ->andWhere('i.tracking != :empty')
            ->setParameter('empty', '');
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find transactions that need status update
     *
     * @return array Transactions needing status update
     */
    public function findNeedingStatusUpdate(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join('Four\ScrEbaySync\Entity\ScrInvoice', 'i', 'WITH', 't.invoice_id = i.id')
            ->where('(t.paid = 0 AND i.paydat IS NOT NULL) OR (t.shipped = 0 AND i.dispatchdat IS NOT NULL) OR (t.canceled = 0 AND i.closed = 1)');
            
        return $qb->getQuery()->getResult();
    }
}