<?php
// src/Repository/ScrRegionRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrRegion;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrRegion entity
 */
class ScrRegionRepository extends EntityRepository
{
    /**
     * Find a region by code
     *
     * @param string $code Region code (e.g., 'DE', 'EU', 'World')
     * @return ScrRegion|null The region or null if not found
     */
    public function findByCode(string $code): ?ScrRegion
    {
        return $this->find($code);
    }
    
    /**
     * Get all regions
     *
     * @return array All regions
     */
    public function findAllRegions(): array
    {
        return $this->findAll();
    }
    
    /**
     * Get default region for a country
     *
     * @param string $countryCode ISO-2 country code
     * @return ScrRegion|null The default region or null if not found
     */
    public function getDefaultForCountry(string $countryCode): ?ScrRegion
    {
        // Get the country entity
        $country = $this->getEntityManager()
            ->getRepository('Four\ScrEbaySync\Entity\ScrCountry')
            ->findOneBy(['ISO2' => $countryCode]);
            
        if (!$country) {
            return null;
        }
        
        // Find region based on country's region
        return $this->findOneBy(['region' => $country->getRegion()]);
    }
}