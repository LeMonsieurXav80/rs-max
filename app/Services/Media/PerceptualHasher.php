<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;

/**
 * Difference hash (dHash) en PHP pur, via GD.
 *
 * Sert a reconnaitre une meme photo re-encodee : quand une publication part sur
 * Facebook ET Instagram, chaque reseau reserve sa propre version, aux octets
 * differents. Un hash cryptographique ne les rapproche pas, celui-ci si.
 *
 * Volontairement independant de PhashComputer, qui exige le venv Python du
 * pipeline Mac (imagehash + PIL) absent du conteneur de production. Les deux
 * empreintes ne sont PAS comparables entre elles.
 *
 * Principe : reduction en 9x8 niveaux de gris, puis un bit par comparaison de
 * deux pixels voisins — 64 bits rendus en 16 caracteres hexadecimaux.
 */
class PerceptualHasher
{
    private const WIDTH = 9;

    private const HEIGHT = 8;

    /**
     * Empreinte d'un fichier image, ou null s'il est illisible.
     */
    public function hash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $source = @imagecreatefromstring(file_get_contents($path));

            if (! $source) {
                return null;
            }

            $small = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            imagecopyresampled(
                $small, $source,
                0, 0, 0, 0,
                self::WIDTH, self::HEIGHT,
                imagesx($source), imagesy($source)
            );
            imagedestroy($source);

            $bits = '';

            for ($y = 0; $y < self::HEIGHT; $y++) {
                for ($x = 0; $x < self::WIDTH - 1; $x++) {
                    $bits .= $this->gray($small, $x, $y) > $this->gray($small, $x + 1, $y) ? '1' : '0';
                }
            }

            imagedestroy($small);

            // 64 bits -> 16 hex, par blocs de 4 pour rester dans les entiers PHP.
            $hex = '';
            foreach (str_split($bits, 4) as $nibble) {
                $hex .= dechex(bindec($nibble));
            }

            return $hex;
        } catch (\Throwable $e) {
            Log::debug('PerceptualHasher: echec du calcul', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Distance de Hamming entre deux empreintes, ou null si incomparables.
     * En dessous de ~6 bits d'ecart, il s'agit de la meme image.
     */
    public function distance(?string $a, ?string $b): ?int
    {
        if (! $a || ! $b || strlen($a) !== strlen($b)) {
            return null;
        }

        $distance = 0;

        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    private function gray(\GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);

        return (int) (
            0.299 * (($rgb >> 16) & 0xFF)
            + 0.587 * (($rgb >> 8) & 0xFF)
            + 0.114 * ($rgb & 0xFF)
        );
    }
}
