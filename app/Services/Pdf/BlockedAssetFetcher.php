<?php

namespace App\Services\Pdf;

use Mpdf\AssetFetcher;
use Mpdf\Exception\AssetFetchingException;

/** Assets are supplied explicitly as bytes; mPDF may not resolve HTML/CSS paths. */
final class BlockedAssetFetcher extends AssetFetcher
{
    // mPDF 8.3.1's CSS parser requires the concrete AssetFetcher type.
    // No parent I/O services are constructed: all resolution is rejected here.
    public function __construct() {}

    public function fetchDataFromPath($path, $originalSrc = null)
    {
        throw new AssetFetchingException('PDF asset resolution is disabled.');
    }
}
