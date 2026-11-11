<?php

namespace App\Services\Pdf;

use Mpdf\Mpdf;

/** The approved multipage decorations from phase 1A.2.1/1A.5. */
final class AcercaMpdf extends Mpdf
{
    public function Header($content = '')
    {
        parent::Header($content);

        $this->SetFillColor(103, 78, 167);
        $this->Rect(0, 0, 13.842520128 * 25.4 / 72, $this->h, 'F');
        $this->SetDrawColor(255, 255, 255);
        $this->SetLineWidth(0.75 * 25.4 / 72);
        $this->Rect(426.679147512 * 25.4 / 72, 28.910990454 * 25.4 / 72,
            13.842520128 * 25.4 / 72, 44.622052609 * 25.4 / 72, 'DF');
    }
}
