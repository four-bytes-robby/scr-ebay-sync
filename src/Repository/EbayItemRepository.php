<?php
// src/Repository/EbayItemRepository.php
namespace Four\ScrEbaySync\Repository;

use Doctrine\ORM\EntityRepository;
use Four\ScrEbaySync\Entity\EbayItem;

/**
 * Repository for EbayItem entity
 */
class EbayItemRepository extends EntityRepository
{
    /**
     * Find active eBay items (quantity > 0)
     *
     * @return array Active items
     */
    public function findActiveItems(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.quantity > 0')
            ->andWhere('e.deleted IS NULL')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find eBay item by eBay item ID
     *
     * @param string $ebayItemId The eBay item ID
     * @return EbayItem|null The item or null if not found
     */
    public function findByEbayItemId(string $ebayItemId): ?EbayItem
    {
        return $this->createQueryBuilder('e')
            ->where('e.ebayItemId = :ebayItemId')
            ->setParameter('ebayItemId', $ebayItemId)
            ->getQuery()
            ->getOneOrNullResult();
    }
    
    /**
     * Find items that need inventory update
     *
     * @return array Items needing inventory update
     */
    public function findNeedingInventoryUpdate(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e')
            ->from(EbayItem::class, 'e')
            ->join('Four\ScrEbaySync\Entity\ScrItem', 'i', 'WITH', 'e.item_id = i.id')
            ->where('e.quantity > 0')
            ->andWhere('e.deleted IS NULL')
            ->andWhere('e.quantity != i.quantity OR e.price != i.price');
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items that need content update
     *
     * @return array Items needing content update
     */
    public function findNeedingContentUpdate(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e')
            ->from(EbayItem::class, 'e')
            ->join('Four\ScrEbaySync\Entity\ScrItem', 'i', 'WITH', 'e.item_id = i.id')
            ->where('e.quantity > 0')
            ->andWhere('e.deleted IS NULL')
            ->andWhere('i.updated > e.updated');
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items that should be ended
     *
     * @return array Items to end
     */
    public function findItemsToDelete(): array
    {
        // Get IDs of eBay available items
        $availableItemsQb = $this->getEntityManager()->createQueryBuilder();
        $availableItemsQb->select('i.id')
            ->from('Four\ScrEbaySync\Entity\ScrItem', 'i')
            ->where('i.ebay = 1')
            ->andWhere('i.price > 0')
            ->andWhere('i.quantity > 0')
            ->andWhere('i.available_from <= CURRENT_TIMESTAMP()')
            ->andWhere('i.available_until IS NULL OR i.available_until >= CURRENT_TIMESTAMP()');
            
        $availableItemIds = $availableItemsQb->getQuery()->getResult();
        $availableItemIds = array_column($availableItemIds, 'id');
        
        // Get eBay items that are active but should be ended
        $qb = $this->createQueryBuilder('e');
        $qb->where('e.quantity > 0')
            ->andWhere('e.deleted IS NULL')
            ->andWhere($qb->expr()->notIn('e.item_id', ':availableItemIds'))
            ->setParameter('availableItemIds', $availableItemIds);
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find items that need to be updated on eBay
     * 
     * @return array Items needing update
     */
    public function findItemsNeedingUpdate(): array
    {
        return $this->findNeedingContentUpdate();
    }
    
    /**
     * Find items that need quantity updates on eBay
     * 
     * @return array Items needing quantity update
     */
    public function findItemsNeedingQuantityUpdate(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e')
            ->from(EbayItem::class, 'e')
            ->join('Four\ScrEbaySync\Entity\ScrItem', 'i', 'WITH', 'e.item_id = i.id')
            ->where('e.deleted IS NULL')
            ->andWhere('(CASE WHEN i.quantity > 3 THEN 3 ELSE i.quantity END) != e.quantity');
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find active listings for unavailable items
     * 
     * @return array Active listings for unavailable items
     */
    public function findActiveListingsForUnavailableItems(): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e')
            ->from(EbayItem::class, 'e')
            ->leftJoin('Four\ScrEbaySync\Entity\ScrItem', 'i', 'WITH', 'e.item_id = i.id')
            ->where('e.deleted IS NULL')
            ->andWhere('e.quantity > 0')
            ->andWhere('(i.id IS NULL OR i.quantity <= 0 OR i.ebay = 0 OR i.price <= 0 OR i.available_until < CURRENT_TIMESTAMP())');
            
        return $qb->getQuery()->getResult();
    }
}