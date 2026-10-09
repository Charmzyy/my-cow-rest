<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

// QR codes for dompdf PDFs (certificates, ear-tag sticker sheets), drawn as plain boxes so no image
// library (GD/Imagick) is needed in the container.
class Qr
{
    // No 0/O or 1/I/L: codes may be read out or typed from paper
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** The QR as rows of dark runs: ['size' => modules per side, 'runs' => [[x, y, length], ...]]. */
    public static function runs(string $text): array
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $size = $matrix->getWidth();
        $runs = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }
                $start = $x;
                while ($x + 1 < $size && $matrix->get($x + 1, $y) === 1) {
                    $x++;
                }
                $runs[] = [$start, $y, $x - $start + 1];
            }
        }

        return ['size' => $size, 'runs' => $runs];
    }

    /** A random code from the unambiguous alphabet. Callers check uniqueness. */
    public static function randomCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
