<?php

namespace App\Support\Markdown;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

final class PageBreakStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        // CommonMark owns fenced/indented code and nested containers. Only a
        // standalone source line at document level can request a PDF break.
        // A consumed prefix belongs to a quote/list container. A list itself
        // may still be open after a blank line; an unindented break closes it.
        if ($cursor->getPosition() !== 0 || $cursor->isIndented() || ! preg_match('/^ {0,3}:::page-break[ \t]*$/D', $cursor->getLine())) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of(new PageBreakParser)->at($cursor);
    }
}
