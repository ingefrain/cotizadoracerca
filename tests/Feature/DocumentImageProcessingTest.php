<?php

namespace Tests\Feature;

use App\Models\ApplicationBranding;
use App\Models\Document;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class DocumentImageProcessingTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public static function sourceFormats(): array
    {
        return [['jpg', 'image/jpeg'], ['png', 'image/png']];
    }

    #[DataProvider('sourceFormats')]
    public function test_avatar_sources_are_converted_to_webp_with_final_checksum_and_bounded_dimensions(string $extension, string $mime): void
    {
        $user = $this->adminUser();
        $source = $this->image($extension, 2048, 1024);
        $sourceBytes = file_get_contents($source->getPathname());
        $document = app(DocumentService::class)->upload($source, $user, $user, 'user.avatar');
        $stored = Storage::disk('local')->get($document->path);
        $details = getimagesizefromstring($stored);

        $this->assertSame('image/webp', $document->mime_type);
        $this->assertSame('webp', $document->extension);
        $this->assertSame('synthetic.'.$extension, $document->original_name);
        $this->assertSame(hash('sha256', $stored), $document->checksum);
        $this->assertSame(strlen($stored), $document->size_bytes);
        $this->assertSame([1024, 512], [$details[0], $details[1]]);
        $this->assertSame($mime, $document->metadata['source_mime']);
        $this->assertTrue($document->metadata['optimized']);
        $this->assertTrue(strlen($stored) < strlen($sourceBytes), 'The representative image should be smaller after WebP optimization.');
    }

    public function test_png_transparency_is_preserved_and_small_logo_is_not_upscaled_or_distorted(): void
    {
        $user = $this->adminUser();
        $avatar = app(DocumentService::class)->upload($this->image('png', 80, 40, true), $user, $user, 'user.avatar');
        $avatarImage = imagecreatefromstring(Storage::disk('local')->get($avatar->path));
        $this->assertSame(127, imagecolorsforindex($avatarImage, imagecolorat($avatarImage, 70, 30))['alpha']);
        imagedestroy($avatarImage);

        $branding = ApplicationBranding::query()->findOrFail(1);
        $logo = app(DocumentService::class)->upload($this->image('png', 240, 80, true), $branding, $user, 'branding.logo');
        $image = getimagesizefromstring(Storage::disk('local')->get($logo->path));
        $this->assertSame([240, 80], [$image[0], $image[1]]);
    }

    public function test_unconfigured_categories_keep_the_uploaded_format_and_processor_failure_leaves_no_file(): void
    {
        $user = $this->adminUser();
        $original = app(DocumentService::class)->upload($this->image('png', 100, 60), $user, $user, 'illustration');
        $this->assertSame('image/png', $original->mime_type);
        $this->assertSame('png', $original->extension);
        $this->assertArrayNotHasKey('optimized', $original->metadata ?? []);

        $policies = config('documents.image_processing');
        config(['documents.image_processing' => array_replace($policies, ['user.avatar' => array_replace($policies['user.avatar'], ['format' => 'avif'])])]);
        try {
            app(DocumentService::class)->upload($this->image('png'), $user, $user, 'user.avatar');
            $this->fail('An unsupported image policy must fail without saving a document.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertSame(1, Document::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('documents'));
    }

    public function test_replacing_an_image_removes_previous_webp_only_after_new_file_is_saved(): void
    {
        $user = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->image('png'), $user, $user, 'user.avatar');
        $oldPath = $document->path;
        $updated = app(DocumentService::class)->update($document, [], $user, $this->image('jpg', 120, 80));

        $this->assertNotSame($oldPath, $updated->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($updated->path);
        $this->assertSame('image/webp', $updated->mime_type);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($updated->path)), $updated->checksum);
    }

    private function image(string $format, int $width = 120, int $height = 80, bool $transparent = false): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, 240, 240, 240));
        for ($x = 0; $x < (int) ($width / 2); $x++) {
            imagefilledrectangle($image, $x, 0, $x, $height - 1, imagecolorallocate($image, 20, 80, 140));
        }
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image, null, 92),
            'png' => imagepng($image),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('synthetic.'.$format, $bytes);
    }
}
