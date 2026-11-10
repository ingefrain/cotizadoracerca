<?php

namespace App\Http\Controllers\Admin;

use App\Support\MarkdownRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MarkdownPreviewController
{
    public function __invoke(Request $request, MarkdownRenderer $renderer): Response
    {
        abort_unless($request->getContentTypeFormat() === 'txt', 415);
        $markdown = $request->getContent();
        abort_if(strlen($markdown) > (int) config('markdown.preview_max_bytes'), 413);
        abort_unless(mb_check_encoding($markdown, 'UTF-8'), 422);

        return response($renderer->render($markdown)->toHtml(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
