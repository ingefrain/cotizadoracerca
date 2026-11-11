<?php

namespace App\Http\Controllers\Admin;

use App\Services\Pdf\CorporatePdfRenderer;
use App\Support\PdfPreviewAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PdfPreviewController
{
    public function render(Request $request, PdfPreviewAccess $access, CorporatePdfRenderer $renderer): Response|JsonResponse
    {
        abort_unless($access->allows($request->user()), 403);

        if ($request->getContentTypeFormat() !== 'txt') {
            return $this->error('Envía el contenido como texto Markdown.', 415);
        }
        $markdown = $request->getContent();
        if (strlen($markdown) > (int) config('pdf.preview_max_bytes')) {
            return $this->error('El contenido supera el límite permitido de 100 KiB.', 413);
        }
        if (! mb_check_encoding($markdown, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $markdown)) {
            return $this->error('El contenido debe ser texto UTF-8 válido.', 422);
        }
        if (trim($markdown) === '') {
            return $this->error('Escribe contenido Markdown antes de generar el PDF.', 422);
        }

        try {
            $bytes = $renderer->render($markdown);
        } catch (Throwable $exception) {
            // Do not log the source document, exception message or request body.
            Log::warning('PDF preview generation failed.', ['exception_type' => $exception::class]);

            return $this->error('No fue posible generar el PDF. Tu Markdown se conserva; revisa el contenido e inténtalo de nuevo.', 503);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }
}
