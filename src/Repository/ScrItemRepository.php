<?php
// src/Repository/ScrItemRepository.php
namespace Four\ScrEbaySync\Repository;

use Doctrine\ORM\EntityRepository;
use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\EbayItem;

class ScrItemRepository extends EntityRepository
{
    /**
     * Findet Artikel, die für eBay verfügbar sind.
     *
     * @param \DateTime $date180daysAgo
     * @param \DateTime $date90daysAgo
     * @param \DateTime $preorderDate Datum bis zu dem Vorbestellungen betrachtet werden
     * @return array
     */
    public function findEbayAvailable(\DateTime $date180daysAgo = null, \DateTime $date90daysAgo = null, \DateTime $preorderDate = null): array
    {
        if ($date180daysAgo === null) {
            $date180daysAgo = new \DateTime('-180 days');
        }
        
        if ($date90daysAgo === null) {
            $date90daysAgo = new \DateTime('-90 days');
        }
        
        if ($preorderDate === null) {
            $preorderDate = new \DateTime('+30 days');
        }
        
        $qb = $this->createQueryBuilder('i');
        
        $qb->where('i.id LIKE :scr OR i.id LIKE :pm OR i.id LIKE :em OR i.id LIKE :ckr')
            ->andWhere('i.ebay = 1')
            ->andWhere('i.price > 0')
            ->andWhere('i.quantity > 0')
            ->andWhere('(i.releasedate <= :preorderDate OR i.releasedate IS NULL)')
            ->andWhere('i.available_from <= :now')
            ->andWhere('(i.available_until IS NULL OR i.available_until >= :now)')
            ->andWhere('(i.updated > :date90daysAgo OR EXISTS (
                SELECT ip.id FROM Four\ScrEbaySync\Entity\ScrInvoicePos ip
                JOIN Four\ScrEbaySync\Entity\ScrInvoice inv WITH ip.invoice_id = inv.id
                WHERE ip.item_id = i.id AND inv.paydat > :date180daysAgo
            ))')
            ->setParameter('scr', 'SCR%')
            ->setParameter('pm', 'PM%')
            ->setParameter('em', 'EM%')
            ->setParameter('ckr', 'CKR%')
            ->setParameter('preorderDate', $preorderDate)
            ->setParameter('now', new \DateTime())
            ->setParameter('date90daysAgo', $date90daysAgo)
            ->setParameter('date180daysAgo', $date180daysAgo);
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items eligible for listing on eBay (not currently listed)
     *
     * @param int $limit Maximum number of items to return (default 20)
     * @return array
     */
    public function findEligibleItemsForEbay(int $limit = 20): array
    {
        // First check which items are already on eBay
        $ebayItemRepo = $this->getEntityManager()->getRepository(EbayItem::class);
        $existingItemsQb = $ebayItemRepo->createQueryBuilder('e')
            ->select('e.item_id')
            ->where('e.deleted IS NULL');

        $existingItemIds = array_column($existingItemsQb->getQuery()->getArrayResult(), 'item_id');

        // Now find items that should be on eBay but aren't listed yet
        $qb = $this->createQueryBuilder('i');
        $qb->where('i.ebay = 1')                                // Marked for eBay
            ->andWhere('i.price > 0')                           // Has a price
            ->andWhere('i.quantity > 0')                        // Has stock
            ->andWhere('i.available_from <= CURRENT_TIMESTAMP()')  // Is currently available
            ->andWhere('(i.available_until IS NULL OR i.available_until > CURRENT_TIMESTAMP())') // Not expired
            ->addOrderBy('i.updated', 'DESC')                   // Recently updated first
            ->setMaxResults($limit);

        // Only include items that aren't already on eBay
        if (!empty($existingItemIds)) {
            $qb->andWhere($qb->expr()->notIn('i.id', ':existingIds'))
               ->setParameter('existingIds', $existingItemIds);
        }
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items with changed quantities
     *
     * @return array
     */
    public function findItemsWithChangedQuantities(): array
    {
        $qb = $this->createQueryBuilder('i');
        $em = $this->getEntityManager();
        
        // Subquery to get eBay items with different quantities
        $subquery = $em->createQueryBuilder()
            ->select('IDENTITY(e.item_id)')
            ->from(EbayItem::class, 'e')
            ->where('e.deleted IS NULL')
            ->andWhere('e.quantity != (CASE WHEN i.quantity > 3 THEN 3 ELSE i.quantity END)');
            
        $qb->join(EbayItem::class, 'e', 'WITH', 'e.item_id = i.id')
           ->where('i.id IN (' . $subquery->getDQL() . ')')
           ->andWhere('i.ebay = 1')
           ->andWhere('i.price > 0')
           ->andWhere('e.deleted IS NULL');
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items that should no longer be available on eBay
     *
     * @return array
     */
    public function findUnavailableItems(): array
    {
        $qb = $this->createQueryBuilder('i');
        
        $qb->join(EbayItem::class, 'e', 'WITH', 'e.item_id = i.id')
           ->where('e.deleted IS NULL')
           ->andWhere('(i.quantity <= 0 OR i.ebay = 0 OR i.price <= 0 OR i.available_until < :now)')
           ->setParameter('now', new \DateTime());
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items with updated information (name, description, etc.)
     *
     * @return array
     */
    public function findUpdatedItems(): array
    {
        $qb = $this->createQueryBuilder('i');
        
        $qb->join(EbayItem::class, 'e', 'WITH', 'e.item_id = i.id')
           ->where('e.deleted IS NULL')
           ->andWhere('i.updated > e.updated')
           ->andWhere('i.ebay = 1')
           ->andWhere('i.price > 0')
           ->andWhere('i.quantity > 0');
        
        return $qb->getQuery()->getResult();
    }
}