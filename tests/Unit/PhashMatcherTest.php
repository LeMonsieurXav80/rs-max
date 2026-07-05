<?php

namespace Tests\Unit;

use App\Services\Media\PhashMatcher;
use PHPUnit\Framework\TestCase;

class PhashMatcherTest extends TestCase
{
    public function test_distance_nulle_pour_deux_phash_identiques(): void
    {
        $this->assertSame(0, PhashMatcher::hamming('a1b2c3d4e5f60718', 'a1b2c3d4e5f60718'));
    }

    public function test_distance_compte_les_bits_differents(): void
    {
        // 0x...0 vs 0x...1 = 1 bit ; 0x...0 vs 0x...f = 4 bits.
        $this->assertSame(1, PhashMatcher::hamming('0000000000000000', '0000000000000001'));
        $this->assertSame(4, PhashMatcher::hamming('0000000000000000', '000000000000000f'));
        // Deux moitiés touchées : 1 bit dans le haut + 1 bit dans le bas.
        $this->assertSame(2, PhashMatcher::hamming('0000000000000000', '1000000000000001'));
        // 64 bits tous différents.
        $this->assertSame(64, PhashMatcher::hamming('0000000000000000', 'ffffffffffffffff'));
    }

    public function test_insensible_a_la_casse(): void
    {
        $this->assertSame(0, PhashMatcher::hamming('ABCDEF0123456789', 'abcdef0123456789'));
    }

    public function test_retourne_null_si_phash_invalide(): void
    {
        $this->assertNull(PhashMatcher::hamming('trop-court', 'a1b2c3d4e5f60718'));
        $this->assertNull(PhashMatcher::hamming('a1b2c3d4e5f60718', 'zzzzzzzzzzzzzzzz'));
        $this->assertNull(PhashMatcher::hamming('', ''));
    }

    public function test_confiance_derivee_de_la_distance(): void
    {
        $this->assertSame(100, PhashMatcher::confidence(0));
        $this->assertSame(92, PhashMatcher::confidence(1));
        $this->assertSame(20, PhashMatcher::confidence(10));
        $this->assertSame(0, PhashMatcher::confidence(13));
        $this->assertSame(0, PhashMatcher::confidence(64)); // borné, jamais négatif
    }
}
