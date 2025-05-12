<?php
// src/Repository/ScrCountryRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrCountry;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrCountry entity
 */
class ScrCountryRepository extends EntityRepository
{
    /**
     * Find a country by its ISO-2 code
     *
     * @param string $iso2 ISO-2 country code (e.g., 'DE')
     * @return ScrCountry|null The country or null if not found
     */
    public function findByISO2(string $iso2): ?ScrCountry
    {
        return $this->findOneBy(['ISO2' => strtoupper($iso2)]);
    }
    
    /**
     * Find a country by its ISO-3 code
     *
     * @param string $iso3 ISO-3 country code (e.g., 'DEU')
     * @return ScrCountry|null The country or null if not found
     */
    public function findByISO3(string $iso3): ?ScrCountry
    {
        return $this->findOneBy(['ISO3' => strtoupper($iso3)]);
    }
    
    /**
     * Find countries by region
     *
     * @param string $region Region code
     * @return array Countries in the specified region
     */
    public function findByRegion(string $region): array
    {
        return $this->findBy(['region' => $region], ['english' => 'ASC']);
    }
    
    /**
     * Find country by name (English or German)
     *
     * @param string $name Name to search for
     * @return ScrCountry|null The country or null if not found
     */
    public function findByName(string $name): ?ScrCountry
    {
        $qb = $this->createQueryBuilder('c');
        $qb->where('c.english LIKE :name OR c.deutsch LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults(1);
            
        return $qb->getQuery()->getOneOrNullResult();
    }
    
    /**
     * Get all EU countries
     *
     * @return array EU countries
     */
    public function findEUCountries(): array
    {
        return $this->findByRegion('EU');
    }
    
    /**
     * Get Germany, Austria, and Switzerland
     *
     * @return array DACH countries
     */
    public function findDACHCountries(): array
    {
        return $this->findBy(['ISO2' => ['DE', 'AT', 'CH']], ['english' => 'ASC']);
    }
}