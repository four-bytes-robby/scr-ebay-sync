<?php
// src/Services/EbayOrder/CustomerProcessor.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Entity\ScrCustomer;
use Four\ScrEbaySync\Entity\ScrCountry;
use Four\ScrEbaySync\Repository\ScrCustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for processing customers from eBay orders
 */
class CustomerProcessor
{
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    /**
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_customer_processor');
    }
    
    /**
     * Create or update a customer from order data
     *
     * @param array $order The order data
     * @return ScrCustomer The customer entity
     */
    public function createOrUpdateCustomer(array $order): ScrCustomer
    {
        $buyer = $order['buyer'];
        $buyerAddress = $buyer['buyerRegistrationAddress'];
        $shipTo = $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'];
        $shippingAddress = $shipTo['contactAddress'];

        // Get country
        $countryCode = $shippingAddress['countryCode'];
        $country = $this->entityManager->getRepository(ScrCountry::class)
            ->findOneBy(['ISO2' => $countryCode]);
        
        if (!$country) {
            throw new \RuntimeException("Country with code {$countryCode} not found");
        }
        
        $isGerman = in_array($countryCode, ['DE', 'AT', 'CH']);
        
        // Try to find customer by email
        /** @var ScrCustomerRepository $customerRepository */
        $customerRepository = $this->entityManager->getRepository(ScrCustomer::class);
        $email = $shipTo['email'] ?? $buyerAddress['email'];
        $customer = $customerRepository->findByEmail($email);
        
        if (!$customer) {
            // Create new customer
            $customer = new ScrCustomer();
            
            // Get next ID
            $nextId = $customerRepository->getNextId();
            
            $customer->setId($nextId);
            $customer->setMail(strtolower($email));
            $customer->setVatIdNo('');
            $customer->setPhone('');
            $customer->setZip('');
            $customer->setLexofficeId('');
        }
        
        // Update customer details
        
        // Name processing
        $fullName = $shipTo['fullName'];
        $nameParts = explode(' ', $fullName);
        
        $firstName = '';
        $lastName = '';
        
        if (count($nameParts) > 1) {
            $lastName = array_pop($nameParts);
            $firstName = implode(' ', $nameParts);
        } else {
            $lastName = $fullName;
        }
        
        $customer->setFirstname($this->shortenString($firstName, 50));
        $customer->setLastname($this->shortenString($lastName, 50));
        $customer->setSalutation(($isGerman ? 'Moin' : 'Hello') . " {$firstName},");
        
        // Address
        $address = $shippingAddress['addressLine1'] ?? '';
        
        if (!empty($shippingAddress['addressLine2'])) {
            $address .= "\n" . $shippingAddress['addressLine2'];
        }
        
        // Company
        if (!empty($shippingAddress['companyName'])) {
            $address = "{$shippingAddress['companyName']}\n{$address}";
        }
        
        $customer->setAddress($address);
        
        if (!empty($shippingAddress['postalCode'])) {
            $customer->setZip(strtoupper($shippingAddress['postalCode']));
        }
        
        // City and state
        $city = $shippingAddress['city'] ?? '';
        
        if (!empty($shippingAddress['stateOrProvince'])) {
            $city = "{$city}, {$shippingAddress['stateOrProvince']}";
        }
        
        $customer->setCity($this->shortenString($city, 50));
        $customer->setCountry($country->getISO3());
        $customer->setDealer(false);
        
        // Phone number processing
        $phone = '';

        if (isset($shipTo['primaryPhone'])) {
            $phone = $shipTo['primaryPhone']['phoneNumber'] ?? '';
        }
        if (!$phone && isset($buyerAddress['primaryPhone'])) {
            $phone = $buyerAddress['primaryPhone']['phoneNumber'] ?? '';
        }

        if (!empty($phone) && $phone !== 'Invalid Request') {
            // Format phone number
            if (!str_starts_with($phone, '00')) {
                // Remove leading zero if present
                if (str_starts_with($phone, '0')) {
                    $phone = substr($phone, 1);
                }
                
                // Add country code
                if ($countryCode === 'DE') {
                    $phone = '0' . $phone;
                } else {
                    $phone = $country->getCallingcode() . $phone;
                }
            }
            
            $customer->setPhone($phone);
        }
        
        // Save customer
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        
        return $customer;
    }
    
    /**
     * Shorten a string to a maximum length
     *
     * @param string $string The string to shorten
     * @param int $maxLength Maximum length
     * @param string $suffix Suffix to append if shortened
     * @return string The shortened string
     */
    private function shortenString(string $string, int $maxLength, string $suffix = ''): string
    {
        if (mb_strlen($string) <= $maxLength) {
            return $string;
        }
        
        return mb_substr($string, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }
}