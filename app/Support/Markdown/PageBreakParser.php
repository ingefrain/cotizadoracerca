<?php

namespace App\Support\Markdown;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

final class PageBreakParser extends AbstractBlockContinueParser
{
    private PageBreak $block;

    public function __construct()
    {
        $this->block = new PageBreak;
    }

    public function getBlock(): PageBreak
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }
}
