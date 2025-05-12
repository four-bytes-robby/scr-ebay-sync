<?php
// src/Repository/ScrInvoiceRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrInvoice;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrInvoice entity
 */
class ScrInvoiceRepository extends EntityRepository
{
    /**
     * Find invoices by source
     *
     * @param string $source Source identifier (e.g., 'ebay')
     * @param \DateTime|null $since Only return invoices created since this date
     * @return array Matching invoices
     */
    public function findBySource(string $source, ?\DateTime $since = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.source = :source')
            ->setParameter('source', $source)
            ->orderBy('i.receivedat', 'DESC');
            
        if ($since) {
            $qb->andWhere('i.receivedat >= :since')
                ->setParameter('since', $since);
        }
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find invoices by source ID
     *
     * @param string $sourceId Source-specific identifier
     * @return ScrInvoice|null The invoice or null if not found
     */
    public function findBySourceId(string $sourceId): ?ScrInvoice
    {
        return $this->findOneBy(['source_id' => $sourceId]);
    }
    
    /**
     * Get the next invoice ID based on year
     *
     * @param int $year Year for invoice ID prefix
     * @return int Next ID
     */
    public function getNextId(int $year): int
    {
        $minId = $year * 100000;
        $maxId = ($year + 1) * 100000 - 1;
        
        $qb = $this->createQueryBuilder('i');
        $qb->select('MAX(i.id)')
            ->where('i.id >= :minId')
            ->andWhere('i.id <= :maxId')
            ->setParameter('minId', $minId)
            ->setParameter('maxId', $maxId);
            
        $maxCurrentId = $qb->getQuery()->getSingleScalarResult();
        return $maxCurrentId ? (int)$maxCurrentId + 1 : $minId + 1;
    }
    
    /**
     * Find unpaid invoices
     *
     * @return array Unpaid invoices
     */
    public function findUnpaid(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.paydat IS NULL')
            ->andWhere('i.closed = 0')
            ->orderBy('i.receivedat', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find unshipped but paid invoices
     *
     * @return array Unshipped invoices
     */
    public function findUnshipped(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.paydat IS NOT NULL')
            ->andWhere('i.dispatchdat IS NULL')
            ->andWhere('i.closed = 0')
            ->orderBy('i.paydat', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find recently shipped invoices
     *
     * @param int $days Number of days to look back
     * @return array Recently shipped invoices
     */
    public function findRecentlyShipped(int $days = 30): array
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");
        
        return $this->createQueryBuilder('i')
            ->where('i.dispatchdat IS NOT NULL')
            ->andWhere('i.dispatchdat >= :date')
            ->setParameter('date', $date)
            ->orderBy('i.dispatchdat', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get imported orders by date
     *
     * @param string $source Source identifier (e.g., 'ebay')
     * @param \DateTime $date Date to check
     * @return array Array of source IDs for orders imported on the given date
     */
    public function getImportedOrderIds(string $source, \DateTime $date): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('i.source_id')
            ->where('i.source = :source')
            ->andWhere('i.receivedat >= :date')
            ->setParameter('source', $source)
            ->setParameter('date', $date)
            ->getQuery()
            ->getArrayResult();
            
        return array_column($result, 'source_id');
    }
}