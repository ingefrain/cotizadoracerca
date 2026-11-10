@props(['value'])

<div {{ $attributes->class('markdown-content') }}>{{ app(\App\Support\MarkdownRenderer::class)->render($value) }}</div>
