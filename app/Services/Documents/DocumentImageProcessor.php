<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class DocumentImageProcessor
{
    /** @return array{contents: string, mime_type: string, extension: string, width: int, height: int}|null */
    public function process(UploadedFile $file, string $category, string $sourceMime): ?array
    {
        $policies = (array) config('documents.image_processing', []);
        $policy = is_array($policies[$category] ?? null) ? $policies[$category] : [];
        if (! ($policy['enabled'] ?? false)) {
            return null;
        }

        $fail = static function (string $message): never {
            throw ValidationException::withMessages(['file' => $message]);
        };
        if (($policy['format'] ?? null) !== 'webp') {
            $fail('El formato de optimización configurado no está disponible.');
        }
        if (! extension_loaded('gd') || ! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            if (($policy['on_unsupported'] ?? 'error') === 'preserve') {
                return null;
            }
            $fail('Este servidor no puede optimizar imágenes WebP.');
        }

        $bytes = file_get_contents($file->getRealPath());
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
        if (! $info || ! isset($types[$info[2]]) || $types[$info[2]] !== $sourceMime) {
            $fail('No se pudo decodificar la imagen seleccionada.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $pixelLimit = max(1, (int) config('documents.image_max_input_pixels', 16000000));
        if ($width < 1 || $height < 1 || $width * $height > $pixelLimit) {
            $fail('La imagen tiene demasiados píxeles para procesarse con seguridad.');
        }
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        $requiredMemory = $width * $height * 8 + 16 * 1024 * 1024;
        if ($memoryLimit > 0 && memory_get_usage(true) + $requiredMemory > $memoryLimit) {
            $fail('La imagen requiere demasiada memoria. Reduce sus dimensiones e inténtalo de nuevo.');
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            $fail('No se pudo decodificar la imagen seleccionada.');
        }

        $target = null;
        try {
            imagepalettetotruecolor($source);
            imagealphablending($source, false);
            imagesavealpha($source, true);
            $width = imagesx($source);
            $height = imagesy($source);
            $maxWidth = max(1, (int) ($policy['max_width'] ?? 1024));
            $maxHeight = max(1, (int) ($policy['max_height'] ?? 1024));
            $scale = min(1, $maxWidth / $width, $maxHeight / $height);
            $outputWidth = max(1, (int) floor($width * $scale));
            $outputHeight = max(1, (int) floor($height * $scale));
            $target = imagecreatetruecolor($outputWidth, $outputHeight);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $outputWidth, $outputHeight, $width, $height);

            ob_start();
            try {
                $encoded = imagewebp($target, null, max(0, min(100, (int) ($policy['quality'] ?? 82))));
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $outputInfo = is_string($output) ? @getimagesizefromstring($output) : false;
            if (! $encoded || ! is_string($output) || $output === '' || ! $outputInfo || $outputInfo[2] !== IMAGETYPE_WEBP
                || $outputInfo[0] !== $outputWidth || $outputInfo[1] !== $outputHeight) {
                $fail('No se pudo optimizar la imagen. Intenta con otra imagen.');
            }

            return ['contents' => $output, 'mime_type' => 'image/webp', 'extension' => 'webp', 'width' => $outputWidth, 'height' => $outputHeight];
        } finally {
            imagedestroy($source);
            if ($target) {
                imagedestroy($target);
            }
        }
    }
}
