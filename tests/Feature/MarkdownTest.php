<?php

namespace Tests\Feature;

use App\Support\MarkdownRenderer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class MarkdownTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_renderer_formats_markdown_without_changing_the_source(): void
    {
        $markdown = "# Título\n\nPárrafo **fuerte** y *suave*.\n\n- Uno\n\n1. Dos\n\n> Cita\n\n[Enlace](https://example.com)\n\n`código`\n\n```\nlinea\n```\n\n---\n\n| A |\n|---|\n| B |";
        $source = $markdown;
        $html = app(MarkdownRenderer::class)->render($markdown)->toHtml();
        foreach (['<h1>', '<p>', '<strong>', '<em>', '<ul>', '<ol>', '<blockquote>', '<a href="https://example.com">', '<code>', '<pre><code>', '<hr', '<table>'] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
        $this->assertSame($source, $markdown);
    }

    public function test_renderer_escapes_html_and_blocks_unsafe_links_and_images(): void
    {
        $markdown = '<script>example</script> <iframe></iframe> <div onclick="example">HTML</div>'
            ."\n\n[JS](javascript:example) [Data](data:image/png;base64,AA) ![remota](https://example.com/image.png) ![local](file:///example.png)";
        $html = app(MarkdownRenderer::class)->render($markdown)->toHtml();

        foreach (['<script', '<iframe', '<div', '<img', 'href="javascript:', 'href="data:', 'image.png'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;div onclick=', $html);
    }

    public function test_preview_requires_authentication_and_csrf(): void
    {
        $this->preview('Texto')->assertRedirect(route('admin.login'));
        $this->actingAsAdmin();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->preview('Texto')->assertStatus(419);
        $this->preview('Texto', ['HTTP_X_CSRF_TOKEN' => session()->token()])->assertOk();
    }

    public function test_preview_returns_the_same_html_without_persisting_content(): void
    {
        $this->actingAsAdmin();
        $this->withSession(['last_activity_recorded_at' => now()->timestamp]);
        $markdown = "    código\n\n**texto**";
        DB::enableQueryLog();
        $response = $this->preview($markdown);
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(app(MarkdownRenderer::class)->render($markdown)->toHtml(), $response->getContent());
        $writes = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^\s*(insert|delete|update|replace)\b/i', $query['query']) && ! str_contains($query['query'], 'last_activity_at'));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
    }

    public function test_preview_limits_bytes_and_only_accepts_plain_text(): void
    {
        $this->actingAsAdmin();
        config(['markdown.preview_max_bytes' => 4]);
        $this->preview('éé')->assertOk();
        $this->preview('ééé')->assertStatus(413);
        $this->postJson(route('admin.markdown.preview'), ['markdown' => 'abc'])->assertStatus(415);
        $this->get('/admin/dev/markdown')->assertNotFound();
    }

    public function test_editor_supports_field_attributes_errors_and_optional_preview(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['description' => ['Revisa el contenido.']]));
        view()->share('errors', $errors);
        $html = Blade::render('<x-admin.markdown-editor name="description" value="<texto>" required :max-length="50" />');
        foreach (['<textarea', 'name="description"', '&lt;texto&gt;', 'required', 'maxlength="50"', 'Revisa el contenido.', 'data-markdown-action="bold"', 'data-markdown-preview'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        $this->assertStringNotContainsString('data-preview-url', Blade::render('<x-admin.markdown-editor name="description" :preview="false" />'));
        $this->assertStringContainsString('class="markdown-panes"', $html);
        $this->assertStringContainsString('data-preview-visible="true"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('aria-label="Mostrar vista previa"', $html);
        $this->assertStringContainsString('data-preview-visible="false"', Blade::render('<x-admin.markdown-editor name="description" :preview-visible="false" />'));
    }

    private function preview(string $source, array $headers = [])
    {
        return $this->call('POST', route('admin.markdown.preview'), [], [], [], $headers + ['CONTENT_TYPE' => 'text/plain'], $source);
    }
}
