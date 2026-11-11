<?php

namespace App\Support;

use App\Support\Markdown\PdfPageBreakExtension;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class MarkdownRenderer
{
    private MarkdownConverter $converter;

    private ?MarkdownConverter $pdfConverter = null;

    public function __construct()
    {
        $this->converter = $this->createConverter();
    }

    private function createConverter(bool $forPdf = false): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 32,
            'max_delimiters_per_line' => 1000,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        if ($forPdf) {
            $environment->addExtension(new PdfPageBreakExtension);
        }
        $environment->addRenderer(Image::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                return $childRenderer->renderNodes($node->children());
            }
        }, 100);
        $environment->addRenderer(Link::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
            {
                Link::assertInstanceOf($node);

                return preg_match('/^data:/i', $node->getUrl())
                    ? $childRenderer->renderNodes($node->children())
                    : null;
            }
        }, 100);

        return new MarkdownConverter($environment);
    }

    public function render(string $markdown): HtmlString
    {
        return new HtmlString($this->converter->convert($markdown)->getContent());
    }

    /** HTML for mPDF only; web previews keep using render(). */
    public function renderForPdf(string $markdown): HtmlString
    {
        $this->pdfConverter ??= $this->createConverter(forPdf: true);

        return new HtmlString($this->pdfConverter->convert($markdown)->getContent());
    }
}
