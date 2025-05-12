<?php
// src/Models/ItemData.php
namespace Four\ScrEbaySync\Models;

use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\EbayItem;

class ItemData
{
    public ?ScrItem $ScrItem = null;
    public ?EbayItem $EbayItem = null;

    public function __construct(?ScrItem $scrItem = null, ?EbayItem $ebayItem = null)
    {
        $this->ScrItem = $scrItem;
        $this->EbayItem = $ebayItem;
    }
}
