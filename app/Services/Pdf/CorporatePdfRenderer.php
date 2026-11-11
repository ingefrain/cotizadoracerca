<?php

namespace App\Services\Pdf;

use App\Support\MarkdownRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Container\SimpleContainer;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

class CorporatePdfRenderer
{
    public function __construct(private readonly MarkdownRenderer $markdown) {}

    /** One rendering path for preview bytes and future final documents. */
    public function render(string $source): string
    {
        $html = $this->markdown->renderForPdf($source)->toHtml();
        // CommonMark permits some local links. PDFs only expose web/email links;
        // strip other hrefs without changing visible text or the shared renderer.
        $html = preg_replace_callback('/\shref="([^"]*)"/i', static function (array $match): string {
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return preg_match('~^(https?://|mailto:)~i', $url) && ! preg_match('/[\x00-\x20\x7f]/', $url)
                ? $match[0] : '';
        }, $html);

        // Never a public path and never a user-supplied path. Each request owns
        // its font/image cache; finally also removes it on rendering failures.
        $temporaryDirectory = storage_path('app/private/pdf-preview/'.Str::uuid());
        File::makeDirectory($temporaryDirectory, 0700, true);

        try {
            $pdf = new AcercaMpdf([
                'mode' => 'utf-8', 'format' => 'Letter', 'tempDir' => $temporaryDirectory,
                'default_font' => 'freesans', 'default_font_size' => 12,
                'margin_left' => 16.5993, 'margin_right' => 11.12,
                'margin_top' => 33.8327, 'margin_bottom' => 16, 'margin_header' => 5.3324,
                'margin_footer' => 9, 'setAutoTopMargin' => 'stretch', 'autoMarginPadding' => 6,
                'shrink_tables_to_fit' => 1, 'use_kwt' => true,
            ], new SimpleContainer(['assetFetcher' => new BlockedAssetFetcher]));
            $pdf->showImageErrors = true;
            $pdf->imageVars['acercaLogoOriginal'] = File::get(resource_path('pdf/logo-acerca.png'));
            $pdf->WriteHTML(File::get(resource_path('css/pdf/corporativo.css')), HTMLParserMode::HEADER_CSS);
            $pdf->SetHTMLHeader(view('pdf.header', ['presenter' => config('pdf.presenter')])->render());
            $pdf->SetHTMLFooter(view('pdf.footer', ['label' => config('pdf.document_label')])->render());
            $pdf->SetTitle(config('pdf.document_label').' - Acerca');
            $pdf->SetAuthor('Acerca');
            $pdf->AddPage();
            $pdf->WriteHTML('<div class="md-body">'.$html.'</div>', HTMLParserMode::HTML_BODY);

            return $pdf->Output('', Destination::STRING_RETURN);
        } finally {
            unset($pdf);
            File::deleteDirectory($temporaryDirectory);
        }
    }
}
