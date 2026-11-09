<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateMedia
{
    private function jpegOrientation(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;
        while ($offset + 4 <= $length && ord($bytes[$offset]) === 0xFF) {
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }
            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                break;
            }

            $payload = $offset + 2;
            if ($marker === 0xE1 && substr($bytes, $payload, 6) === "Exif\0\0") {
                $tiff = $payload + 6;
                $littleEndian = substr($bytes, $tiff, 2) === 'II';
                if (! $littleEndian && substr($bytes, $tiff, 2) !== 'MM') {
                    return 1;
                }
                $read16 = static fn (int $at): ?int => $at + 2 <= $length
                    ? unpack($littleEndian ? 'v' : 'n', substr($bytes, $at, 2))[1]
                    : null;
                $read32 = static fn (int $at): ?int => $at + 4 <= $length
                    ? unpack($littleEndian ? 'V' : 'N', substr($bytes, $at, 4))[1]
                    : null;
                if ($read16($tiff + 2) !== 42) {
                    return 1;
                }
                $ifd = $tiff + ($read32($tiff + 4) ?? $length);
                $entries = $read16($ifd);
                if ($entries === null || $ifd + 2 + ($entries * 12) > $offset + $segmentLength) {
                    return 1;
                }
                for ($i = 0; $i < $entries; $i++) {
                    $entry = $ifd + 2 + ($i * 12);
                    if ($read16($entry) !== 0x0112 || $read16($entry + 2) !== 3 || $read32($entry + 4) < 1) {
                        continue;
                    }
                    $value = $read16($entry + 8);

                    return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
                }

                return 1;
            }

            $offset += $segmentLength;
        }

        return 1;
    }

    private function orientImage(\GdImage $image, int $orientation): \GdImage
    {
        $flip = match ($orientation) {
            2, 7 => IMG_FLIP_HORIZONTAL,
            4, 5 => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null) {
            imageflip($image, $flip);
        }
        $rotation = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };
        if ($rotation === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $rotation, 0);
        if (! $rotated) {
            throw new \RuntimeException('IMAGE_ORIENTATION_FAILED');
        }
        imagedestroy($image);

        return $rotated;
    }

    public function cleanup(array $paths): void
    {
        if ($paths) {
            DB::table('storage_cleanups')->insert(['paths' => json_encode(array_values(array_unique($paths)), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function process(array $source): array
    {
        $disk = Storage::disk('local');
        $extension = match ($source['mime']) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'image/png' => 'png',
            default => 'jpg',
        };
        $path = 'media/'.Str::uuid().'.'.$extension;
        $bytes = $disk->get($source['path']);
        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('SOURCE_READ_FAILED');
        }
        if (! str_starts_with($source['mime'], 'video/')) {
            if (! extension_loaded('gd')) {
                throw new \RuntimeException('GD_REQUIRED');
            }
            $size = getimagesizefromstring($bytes);
            if (! $size || $size[0] * $size[1] > 40000000) {
                throw new \RuntimeException('IMAGE_DIMENSIONS_INVALID');
            }
            $image = imagecreatefromstring($bytes);
            if (! $image) {
                throw new \RuntimeException('IMAGE_DECODE_FAILED');
            }
            if ($source['mime'] === 'image/jpeg') {
                $image = $this->orientImage($image, $this->jpegOrientation($bytes));
            }
            $ratio = min(1, 1080 / max(imagesx($image), imagesy($image)));
            $target = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), imagesx($image), imagesy($image));
                ob_start();
                try {
                    $ok = $source['mime'] === 'image/png' ? imagepng($target, null, 7) : imagejpeg($target, null, 85);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $ok) {
                    throw new \RuntimeException('IMAGE_ENCODE_FAILED');
                }
            } finally {
                imagedestroy($image);
                imagedestroy($target);
            }
        }
        if (! $disk->put($path, $bytes)) {
            throw new \RuntimeException('MEDIA_WRITE_FAILED');
        }

        return ['file_path' => $path, 'file_type' => str_starts_with($source['mime'], 'video/') ? 'video' : 'image', 'mime_type' => $source['mime'], 'file_size_kb' => (int) ceil(strlen($bytes) / 1024)];
    }
}
