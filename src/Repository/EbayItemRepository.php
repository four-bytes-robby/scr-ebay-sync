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
     * Find all eBay items with their corresponding SCR items using OneToOne LEFT JOIN
     * This utilizes the entity-level OneToOne relationship for clean queries
     *
     * @return array Array of EbayItem entities with loaded ScrItem relationships
     */
    public function findAllWithScrItems(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.scrItem', 's')
            ->addSelect('s')
            ->where('e.deleted IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find eBay items that have corresponding SCR items
     *
     * @return array EbayItem entities with non-null ScrItem relationships
     */
    public function findWithValidScrItems(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.scrItem', 's')
            ->addSelect('s')
            ->where('e.deleted IS NULL')
            ->andWhere('s.id IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find eBay items without corresponding SCR items (orphaned)
     *
     * @return array EbayItem entities with null ScrItem relationships
     */
    public function findOrphanedEbayItems(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.scrItem', 's')
            ->where('e.deleted IS NULL')
            ->andWhere('s.id IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Advanced query: Find eBay items with quantity mismatch compared to SCR items
     *
     * @return array EbayItem entities where quantities don't match
     */
    public function findQuantityMismatches(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.scrItem', 's')
            ->addSelect('s')
            ->where('e.deleted IS NULL')
            ->andWhere('s.id IS NOT NULL')
            ->andWhere('e.quantity != s.quantity')
            ->getQuery()
            ->getResult();
    }
}