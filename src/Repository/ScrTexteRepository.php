<?php
// src/Repository/ScrTexteRepository.php
namespace Four\ScrEbaySync\Repository;

use Four\ScrEbaySync\Entity\ScrTexte;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ScrTexte entity
 */
class ScrTexteRepository extends EntityRepository
{
    /**
     * Find text by variable name
     *
     * @param string $varName The variable name
     * @return ScrTexte|null The text entity or null if not found
     */
    public function findByVarName(string $varName): ?ScrTexte
    {
        return $this->find($varName);
    }
    
    /**
     * Get text content by variable name
     *
     * @param string $varName The variable name
     * @param string $default Default value if not found
     * @return string The text content or default value if not found
     */
    public function getTextContent(string $varName, string $default = ''): string
    {
        $text = $this->find($varName);
        return $text ? $text->getTxtText() : $default;
    }
}