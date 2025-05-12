<?php
// src/Entity/ScrCustomer.php
namespace Four\ScrEbaySync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Four\ScrEbaySync\Repository\ScrCustomerRepository;

#[ORM\Entity(repositoryClass: ScrCustomerRepository::class)]
#[ORM\Table(name: "scr_customers")]
class ScrCustomer
{
    #[ORM\Id]
    #[ORM\Column(type: "integer")]
    private int $id;
    
    #[ORM\Column(type: "string", length: 50)]
    private string $salutation;
    
    #[ORM\Column(type: "string", length: 50)]
    private string $firstname;
    
    #[ORM\Column(type: "string", length: 50)]
    private string $lastname;
    
    #[ORM\Column(type: "text")]
    private string $address;
    
    #[ORM\Column(type: "string", length: 20)]
    private string $zip;
    
    #[ORM\Column(type: "string", length: 50)]
    private string $city;
    
    #[ORM\Column(type: "string", length: 3)]
    private string $country;
    
    #[ORM\Column(type: "string", length: 100)]
    private string $mail;
    
    #[ORM\Column(type: "boolean")]
    private bool $dealer;
    
    #[ORM\Column(type: "string", length: 20)]
    private string $phone;
    
    #[ORM\Column(type: "integer")]
    private int $MailAddedToNewsletter = 0;
    
    #[ORM\Column(type: "string", length: 30)]
    private string $vatIdNo;
    
    #[ORM\Column(type: "integer")]
    private int $customerno = 0;
    
    #[ORM\Column(type: "string", length: 36)]
    private string $lexoffice_id;
    
    // Getters and setters
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    public function getSalutation(): string
    {
        return $this->salutation;
    }
    
    public function setSalutation(string $salutation): self
    {
        $this->salutation = $salutation;
        return $this;
    }
    
    public function getFirstname(): string
    {
        return $this->firstname;
    }
    
    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;
        return $this;
    }
    
    public function getLastname(): string
    {
        return $this->lastname;
    }
    
    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;
        return $this;
    }
    
    public function getAddress(): string
    {
        return $this->address;
    }
    
    public function setAddress(string $address): self
    {
        $this->address = $address;
        return $this;
    }
    
    public function getZip(): string
    {
        return $this->zip;
    }
    
    public function setZip(string $zip): self
    {
        $this->zip = $zip;
        return $this;
    }
    
    public function getCity(): string
    {
        return $this->city;
    }
    
    public function setCity(string $city): self
    {
        $this->city = $city;
        return $this;
    }
    
    public function getCountry(): string
    {
        return $this->country;
    }
    
    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }
    
    public function getMail(): string
    {
        return $this->mail;
    }
    
    public function setMail(string $mail): self
    {
        $this->mail = $mail;
        return $this;
    }
    
    public function isDealer(): bool
    {
        return $this->dealer;
    }
    
    public function setDealer(bool $dealer): self
    {
        $this->dealer = $dealer;
        return $this;
    }
    
    public function getPhone(): string
    {
        return $this->phone;
    }
    
    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }
    
    public function getMailAddedToNewsletter(): int
    {
        return $this->MailAddedToNewsletter;
    }
    
    public function setMailAddedToNewsletter(int $MailAddedToNewsletter): self
    {
        $this->MailAddedToNewsletter = $MailAddedToNewsletter;
        return $this;
    }
    
    public function getVatIdNo(): string
    {
        return $this->vatIdNo;
    }
    
    public function setVatIdNo(string $vatIdNo): self
    {
        $this->vatIdNo = $vatIdNo;
        return $this;
    }
    
    public function getCustomerno(): int
    {
        return $this->customerno;
    }
    
    public function setCustomerno(int $customerno): self
    {
        $this->customerno = $customerno;
        return $this;
    }
    
    public function getLexofficeId(): string
    {
        return $this->lexoffice_id;
    }
    
    public function setLexofficeId(string $lexoffice_id): self
    {
        $this->lexoffice_id = $lexoffice_id;
        return $this;
    }
    
    /**
     * Get full name (firstname + lastname)
     *
     * @return string The full name
     */
    public function getFullName(): string
    {
        return trim($this->firstname . ' ' . $this->lastname);
    }
}