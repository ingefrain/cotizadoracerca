<?php

namespace App\Services;

use App\Models\ApplicationBranding;
use App\Models\Document;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BrandingFavicon
{
    private const SNAPSHOT = 'branding/favicon-png64-v1.json';

    private const SIZE = 64;

    public function __construct(private readonly ManagedDocumentPath $paths) {}

    public function forLogo(?Document $logo): string
    {
        try {
            if ($logo === null || $logo->trashed()
                || $logo->category !== 'branding.logo'
                || $logo->documentable_type !== (new ApplicationBranding)->getMorphClass()
                || (string) $logo->documentable_id !== '1'
                || ! preg_match('/^[a-f0-9]{64}$/', $logo->checksum)
                || ! in_array($logo->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true)
                || ! $this->paths->matches($logo)) {
                return $this->reset();
            }

            $disk = Storage::disk($logo->disk);
            if (! $disk->exists($logo->path)) {
                return $this->reset();
            }
            $snapshot = $this->snapshot();
            if ($snapshot !== null && hash_equals($snapshot['checksum'], $logo->checksum)) {
                return 'data:image/png;base64,'.$snapshot['png'];
            }

            $rules = (array) config('documents.category_rules', []);
            $maximum = max(1, (int) ($rules['branding.logo']['max_size_kb'] ?? 5120)) * 1024;
            if ($disk->size($logo->path) > $maximum) {
                return $this->reset();
            }
            $contents = $disk->get($logo->path);
            if (! is_string($contents) || ! hash_equals($logo->checksum, hash('sha256', $contents))) {
                return $this->reset();
            }
            $png = $this->thumbnail($contents, $logo->mime_type);
            if ($png === null) {
                return $this->reset();
            }
            $snapshot = ['checksum' => $logo->checksum, 'png' => base64_encode($png)];
            // Only this small derivative is embedded publicly; the source stays private.
            Storage::disk('local')->put(self::SNAPSHOT, json_encode($snapshot, JSON_THROW_ON_ERROR), 'private');

            return 'data:image/png;base64,'.$snapshot['png'];
        } catch (Throwable) {
            return $this->reset();
        }
    }

    /** No database or GD dependency: safe while pre-rendering the maintenance page. */
    public function cached(): string
    {
        $snapshot = $this->snapshot();

        return $snapshot !== null ? 'data:image/png;base64,'.$snapshot['png'] : $this->fallback();
    }

    public function fallback(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(public_path('assets/base-admin/favicon.svg')));
    }

    private function reset(): string
    {
        try {
            Storage::disk('local')->delete(self::SNAPSHOT);
        } catch (Throwable) {
            // An unavailable cache must not prevent access to the application.
        }

        return $this->fallback();
    }

    /** @return array{checksum: string, png: string}|null */
    private function snapshot(): ?array
    {
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::SNAPSHOT) || $disk->size(self::SNAPSHOT) > 40000) {
                return null;
            }
            $snapshot = json_decode($disk->get(self::SNAPSHOT), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! is_string($snapshot['checksum'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $snapshot['checksum']) || ! is_string($snapshot['png'] ?? null)) {
                return null;
            }
            $png = base64_decode($snapshot['png'], true);
            $size = $png !== false ? @getimagesizefromstring($png) : false;

            return $size !== false && $size[0] === self::SIZE && $size[1] === self::SIZE && $size[2] === IMAGETYPE_PNG
                ? $snapshot : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function thumbnail(string $contents, string $mime): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagepng')) {
            return null;
        }
        $size = @getimagesizefromstring($contents);
        if ($size === false || ($size['mime'] ?? '') !== $mime || $size[0] < 1 || $size[1] < 1
            || (int) config('documents.image_max_input_pixels', 16000000) < $size[0] * $size[1]) {
            return null;
        }
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoryLimit > 0 && memory_get_usage(true) + $size[0] * $size[1] * 8 + 16777216 > $memoryLimit) {
            return null;
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            $scale = min(self::SIZE / $size[0], self::SIZE / $size[1]);
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
            imagecopyresampled($canvas, $source, intdiv(self::SIZE - $width, 2), intdiv(self::SIZE - $height, 2), 0, 0, $width, $height, $size[0], $size[1]);
            ob_start();
            try {
                $written = imagepng($canvas);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            return $written && is_string($png) ? $png : null;
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }
}
