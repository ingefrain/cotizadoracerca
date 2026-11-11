<?php

namespace Tests\Unit;

use App\Support\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PdfMarkdownTest extends TestCase
{
    #[DataProvider('breakCases')]
    public function test_pdf_breaks_are_normalized(string $markdown, string $expected): void
    {
        $renderer = new MarkdownRenderer;

        $this->assertSame($expected, $renderer->renderForPdf($markdown)->toHtml());
        $this->assertStringNotContainsString('<pagebreak', $renderer->render($markdown)->toHtml());
    }

    public static function breakCases(): array
    {
        return [
            'between paragraphs without blank lines' => ["Uno\n:::page-break\nDos", "<p>Uno</p>\n<pagebreak />\n<p>Dos</p>\n"],
            'outer spaces' => ["Uno\n\n   :::page-break \t\n\nDos", "<p>Uno</p>\n<pagebreak />\n<p>Dos</p>\n"],
            'CRLF' => ["Uno\r\n\r\n:::page-break\r\n\r\nDos", "<p>Uno</p>\n<pagebreak />\n<p>Dos</p>\n"],
            'leading' => [":::page-break\n\nUno", "<p>Uno</p>\n"],
            'trailing' => ["Uno\n\n:::page-break", "<p>Uno</p>\n"],
            'consecutive' => ["Uno\n\n:::page-break\n:::page-break\n\nDos", "<p>Uno</p>\n<pagebreak />\n<p>Dos</p>\n"],
            'consecutive separated by whitespace' => ["Uno\n\n:::page-break\n \t\n:::page-break\n\nDos", "<p>Uno</p>\n<pagebreak />\n<p>Dos</p>\n"],
            'multiple at both edges' => [":::page-break\n:::page-break\n\nUno\n\n:::page-break\n:::page-break", "<p>Uno</p>\n"],
            'only directives' => [":::page-break\n\n:::page-break", ''],
            'empty' => ['', ''],
            'sections' => ["## Uno\n\nTexto.\n\n:::page-break\n\n## Dos\n\nTexto final.", "<h2>Uno</h2>\n<p>Texto.</p>\n<pagebreak />\n<h2>Dos</h2>\n<p>Texto final.</p>\n"],
        ];
    }

    #[DataProvider('literalCases')]
    public function test_non_directive_contexts_keep_standard_markdown(string $markdown): void
    {
        $renderer = new MarkdownRenderer;
        $web = $renderer->render($markdown)->toHtml();
        $pdf = $renderer->renderForPdf($markdown)->toHtml();

        $this->assertSame($web, $pdf);
        $this->assertStringNotContainsString('<pagebreak', $pdf);
    }

    public static function literalCases(): array
    {
        return [
            'backtick fence' => ["```markdown\n:::page-break\n```"],
            'tilde fence' => ["~~~\n:::page-break\n~~~"],
            'unclosed fence' => ["```\n:::page-break"],
            'long fence with shorter interior fence' => ["````\n```\n:::page-break\n````"],
            'indented code' => ['    :::page-break'],
            'tab-indented code' => ["\t:::page-break"],
            'inline code' => ['Usar `:::page-break` aquí.'],
            'paragraph text' => ['Texto :::page-break dentro del párrafo.'],
            'suffix' => [':::page-break extra'],
            'wrong case' => [':::PAGE-BREAK'],
            'escape' => ['\\:::page-break'],
            'quote' => ['> :::page-break'],
            'list' => ['- :::page-break'],
            'nested list line' => ["- Texto\n\n  :::page-break"],
            'table cell' => ["| A | B |\n|---|---|\n| :::page-break | Texto |"],
            'other directive' => [':::box'],
            'no directives' => ["# Título\n\nTexto **fuerte** y *cursivo*, áéíóúñü.\n\n- Uno\n\n[Seguro](https://example.com)"],
        ];
    }

    public function test_break_after_table_preserves_table_html(): void
    {
        $renderer = new MarkdownRenderer;
        $table = "| Concepto | Importe |\n|---|---:|\n| Literal | $12,000 |";

        $this->assertSame(
            $renderer->render($table)->toHtml()."<pagebreak />\n<h2>Siguiente</h2>\n",
            $renderer->renderForPdf($table."\n\n:::page-break\n\n## Siguiente")->toHtml(),
        );
    }

    public function test_reference_links_work_across_a_break(): void
    {
        $renderer = new MarkdownRenderer;
        $html = $renderer->renderForPdf("[Enlace][sitio]\n\n:::page-break\n\nDespués.\n\n[sitio]: https://example.com")->toHtml();

        $this->assertSame("<p><a href=\"https://example.com\">Enlace</a></p>\n<pagebreak />\n<p>Después.</p>\n", $html);
    }

    public function test_break_after_a_list_is_a_document_block(): void
    {
        $renderer = new MarkdownRenderer;

        $this->assertSame(
            "<ul>\n<li>Condición</li>\n</ul>\n<pagebreak />\n<h2>Exclusiones</h2>\n",
            $renderer->renderForPdf("- Condición\n\n:::page-break\n\n## Exclusiones")->toHtml(),
        );
    }

    public function test_pdf_context_preserves_security_rules(): void
    {
        $renderer = new MarkdownRenderer;
        $input = "<pagebreak />\n\n<script>alert(1)</script>\n\n<style>p{color:red}</style>\n\n[JS](javascript:alert(1)) [Data](data:text/html,evil) ![Solo texto](file:///private.png)\n\n:::page-break\n\nFinal";
        $html = $renderer->renderForPdf($input)->toHtml();

        $this->assertSame(1, substr_count($html, '<pagebreak />'));
        $this->assertStringContainsString('&lt;pagebreak /&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Solo texto', $html);
        foreach (['<script', '<style', '<img', 'href="javascript:', 'href="data:', 'src=', 'file:///'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
    }

    public function test_pdf_state_does_not_leak_to_other_documents_or_web_preview(): void
    {
        $renderer = new MarkdownRenderer;
        $input = "Uno\n\n:::page-break\n\nDos";
        $web = $renderer->render($input)->toHtml();
        $renderer->renderForPdf($input);

        $this->assertSame($web, $renderer->render($input)->toHtml());
        $this->assertSame("<p>Único</p>\n", $renderer->renderForPdf(":::page-break\n\nÚnico\n\n:::page-break")->toHtml());
    }
}
