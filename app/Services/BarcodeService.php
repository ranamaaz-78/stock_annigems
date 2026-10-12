<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * BarcodeService
 *
 * Renders Code 128 PNG images for stone packet labels. The barcode *value*
 * is the stone_code; this service never invents a second identifier.
 */
class BarcodeService
{
    /**
     * PNG bytes for a Code 128 barcode of the given payload.
     */
    public function png(string $payload, int $barWidth = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorPNG;

        return $generator->getBarcode($payload, $generator::TYPE_CODE_128, $barWidth, $height);
    }

    /**
     * Code 128 as an SVG that scales to any size without blurring.
     *
     * The fixed width and height are removed and the drawing is told to
     * stretch to whatever box the label gives it, so a label of any
     * dimensions prints sharp bars. Only bars are drawn; the number under
     * them is text the label adds.
     */
    public function svg(string $payload): string
    {
        $generator = new BarcodeGeneratorSVG;
        $svg = $generator->getBarcode($payload, $generator::TYPE_CODE_128, 2, 40);

        $svg = (string) preg_replace('/<svg\s+width="[^"]*"\s+height="[^"]*"/', '<svg width="100%" height="100%" preserveAspectRatio="none"', $svg, 1);
        // The desc element repeats the number as hidden text; drop it.
        $svg = (string) preg_replace('/<desc>.*?<\/desc>/s', '', $svg);

        // Inline SVG starts at the <svg> tag: no XML declaration or DOCTYPE before it.
        $start = strpos($svg, '<svg');

        return trim($start === false ? $svg : substr($svg, $start));
    }

    /**
     * Data-URI suitable for <img src> on screen and print views.
     */
    public function dataUri(string $payload, int $barWidth = 2, int $height = 50): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($payload, $barWidth, $height));
    }
}
