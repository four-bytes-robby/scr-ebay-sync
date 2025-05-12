<?php
// src/Repository/ScrCustomerRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrCustomer;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrCustomer entity
 */
class ScrCustomerRepository extends EntityRepository
{
    /**
     * Find customer by email
     *
     * @param string $email The customer email
     * @return ScrCustomer|null The customer or null if not found
     */
    public function findByEmail(string $email): ?ScrCustomer
    {
        return $this->findOneBy(['mail' => strtolower($email)]);
    }
    
    /**
     * Get the next available customer ID
     *
     * @return int The next customer ID
     */
    public function getNextId(): int
    {
        $qb = $this->createQueryBuilder('c');
        $qb->select('MAX(c.id)');
        
        $maxId = $qb->getQuery()->getSingleScalarResult();
        return $maxId ? (int)$maxId + 1 : 1;
    }
    
    /**
     * Find customers with recent orders
     *
     * @param \DateTime $since Find customers with orders since this date
     * @param int $limit Maximum number of customers to return
     * @return array Customers with recent orders
     */
    public function findWithRecentOrders(\DateTime $since, int $limit = 100): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('c')
            ->from(ScrCustomer::class, 'c')
            ->join('Four\ScrEbaySync\Entity\ScrInvoice', 'i', 'WITH', 'i.customer_id = c.id')
            ->where('i.receivedat >= :since')
            ->setParameter('since', $since)
            ->groupBy('c.id')
            ->orderBy('MAX(i.receivedat)', 'DESC')
            ->setMaxResults($limit);
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find customers by name (first or last)
     *
     * @param string $name Name to search for
     * @param int $limit Maximum number of results
     * @return array Matching customers
     */
    public function findByName(string $name, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('c');
        $qb->where('c.firstname LIKE :name OR c.lastname LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults($limit);
            
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find customers who have made eBay purchases
     *
     * @param int $limit Maximum number of results
     * @return array Customers who have ordered via eBay
     */
    public function findEbayCustomers(int $limit = 100): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('c')
            ->from(ScrCustomer::class, 'c')
            ->join('Four\ScrEbaySync\Entity\ScrInvoice', 'i', 'WITH', 'i.customer_id = c.id')
            ->where('i.source = :source')
            ->setParameter('source', 'ebay')
            ->groupBy('c.id')
            ->orderBy('MAX(i.receivedat)', 'DESC')
            ->setMaxResults($limit);
            
        return $qb->getQuery()->getResult();
    }
}