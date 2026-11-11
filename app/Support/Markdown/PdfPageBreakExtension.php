<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class PdfPageBreakExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new PageBreakStartParser, 30);
        $environment->addRenderer(PageBreak::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                PageBreak::assertInstanceOf($node);

                // Trusted output only: user HTML is still escaped by the renderer.
                return '<pagebreak />';
            }
        });
        $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
            $hasContent = false;
            $pending = null;
            foreach ($event->getDocument()->children() as $block) {
                if ($block instanceof PageBreak) {
                    if (! $hasContent || $pending !== null) {
                        $block->detach();
                    } else {
                        $pending = $block;
                    }

                    continue;
                }

                $hasContent = true;
                $pending = null;
            }

            // A break is useful only when followed by another content block.
            $pending?->detach();
        });
    }
}
