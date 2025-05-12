<?php
// src/Services/EbayListing/DescriptionFormatter.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Four\ScrEbaySync\Entity\ScrItem;
use Monolog\Logger;

/**
 * Service for formatting item descriptions
 */
class DescriptionFormatter
{
    private ScrItem $scrItem;
    private ?ImageService $imageService = null;
    private ?Logger $logger = null;
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param Logger|null $logger Optional logger
     */
    public function __construct(ScrItem $scrItem, ?Logger $logger = null)
    {
        $this->scrItem = $scrItem;
        $this->logger = $logger ?? new Logger('description_formatter');
    }
    
    /**
     * Set the image service
     *
     * @param ImageService $imageService
     * @return self
     */
    public function setImageService(ImageService $imageService): self
    {
        $this->imageService = $imageService;
        return $this;
    }
    
    /**
     * Get the eBay description
     *
     * @return string The HTML description
     */
    public function getDescription(): string
    {
        // Get description template
        $template = $this->getDescriptionTemplate();
        
        // Title formatter to extract title and format
        $titleFormatter = new TitleFormatter($this->scrItem);
        
        // Replace placeholders
        $template = str_replace('$name', $titleFormatter->getTitle(), $template);
        
        // Image handling
        $pictureUrl = '';
        if ($this->imageService) {
            $pictureUrl = $this->imageService->getEmbeddPictureUrl();
        }
        $template = str_replace('$pictureUrl', $pictureUrl, $template);
        $template = str_replace('$pictureAlt', htmlspecialchars($titleFormatter->getTitle()), $template);
        
        // Process text content
        $text = $this->scrItem->getDeutsch();
        $text = preg_replace('/<[^>]*>/', '', $text); // Strip tags
        $text = htmlspecialchars($text);
        $text = str_replace("\t", "&nbsp;", $text);
        $text = str_replace("\n", "<br>", $text);
        
        $template = str_replace('$text', $text, $template);
        $template = str_replace('$label', $this->scrItem->getLabel(), $template);
        
        // Release year
        $releaseYear = '';
        if ($this->scrItem->getReleasedate()) {
            $releaseYear = $this->scrItem->getReleasedate()->format('Y');
            $template = str_replace('$releaseYear', $releaseYear, $template);
            $template = str_replace('$release', $this->scrItem->getReleasedate()->format('d.m.Y'), $template);
        } else {
            $template = str_replace('$releaseYear', '', $template);
            $template = str_replace('$release', '', $template);
        }
        
        return $template;
    }
    
    /**
     * Get the description template
     *
     * @return string The template HTML
     */
    private function getDescriptionTemplate(): string
    {
        $templatePath = __DIR__ . '/../../../templates/description_template.html';
        $defaultTemplate = '<div style="font-family: \'Market Sans\', Arial, sans-serif;">
<h3>$name</h1>
<p>$text</p>
<p>$releaseYear $label</p>

<p style="color:#800;">
<b>Versandzeiten</b><br>
Leider zeigt eBay außerhalb Deutschlands falsche Schätzwerte für die Lieferzeit von Artikeln an. Speziell Überseegebiete haben längere Lieferzeiten. Im Zweifel bitten wir dich, nachzufragen.<br>
In Deutschland wird speziell bei Warenpost (z.B. bei Versand einer CD) bei eBay eine erfolgte Zustellung gemeldet, obwohl die Sendung erst im lokalen Verteilzentrum eingetroffen ist. Bitte prüfe hierzu den detaillierten Sendungsstatus, dort steht es oftmals korrekt.<br>
<br>
<b>Delivery times</b><br>
Unfortunately, eBay displays incorrect estimates for the delivery time of items outside Germany. Overseas areas in particular have longer delivery times. If in doubt, please ask us.<br>
</p>

<p style="color:#800;">
<b>Versand von Schallplatten/LPs</b><br>
Wir versenden die Schallplatten in der Regel eingeschweißt als Originalware.<br>
Wenn Du explizit Platten außerhalb des Covers verschickt haben möchtest und damit das Risiko einer Beschädigung des Covers vermeiden möchtest, informiere uns bitte mit einer kurzen Nachricht im Anmerkungsfeld beim Bestellvorgang. Wir senden die Platten in diesem Fall außerhalb des Covers, um Schäden an Cover und/oder Innenhülle zu vermeiden. Bei bedruckten Innenhüllen werden die Platten selbst in neuen antistatischen Innenhüllen versendet.<br>
<br>
<b>Shipping of vinyl records/LPs</b><br>
We will send you records - if possible - new and sealed.<br>
If you explicitely want us to ship your records outside of the cover and therfor minimize the risk of damages to the cover please inform us with a short message in the annotation field in the checkout. We will then send your records separated from the covers to avoid damages like seam splits on the cover and/or inner sleeve. We will place records housed in a printed inner sleeve in new antistatic bags.
</p>
</div>';
        
        if (file_exists($templatePath)) {
            return file_get_contents($templatePath);
        }
        
        $this->logger->warning('Description template not found at ' . $templatePath);
        return $defaultTemplate;
    }
}