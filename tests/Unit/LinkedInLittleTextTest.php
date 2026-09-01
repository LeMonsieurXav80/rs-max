<?php

namespace Tests\Unit;

use App\Services\Adapters\LinkedInAdapter;
use PHPUnit\Framework\TestCase;

class LinkedInLittleTextTest extends TestCase
{
    private function escape(string $content): string
    {
        $method = new \ReflectionMethod(LinkedInAdapter::class, 'escapeLittleText');

        return $method->invoke(new LinkedInAdapter, $content);
    }

    public function test_les_caracteres_reserves_sont_echappes(): void
    {
        $this->assertSame(
            'Promo \(2 pour 1\) \*aujourd\_hui\* \#soldes',
            $this->escape('Promo (2 pour 1) *aujourd_hui* #soldes')
        );
    }

    public function test_la_puce_unicode_reste_intacte(): void
    {
        // • (U+2022) n'est pas réservé : une liste à puces doit passer telle quelle.
        $this->assertSame(
            "• Premier point\n• Deuxième point",
            $this->escape("• Premier point\n• Deuxième point")
        );
    }

    public function test_le_backslash_est_echappe_en_premier_sans_doubler_le_travail(): void
    {
        // `\(` en entrée → `\\` puis `\(` : le backslash d'origine et la
        // parenthèse sont échappés une fois chacun, pas deux.
        $this->assertSame('a\\\\b\\(c', $this->escape('a\\b(c'));
    }

    public function test_un_texte_sans_caractere_reserve_est_inchange(): void
    {
        $this->assertSame(
            'Bonjour à tous, on se voit demain !',
            $this->escape('Bonjour à tous, on se voit demain !')
        );
    }

    public function test_tous_les_caracteres_reserves_de_la_doc_sont_couverts(): void
    {
        $this->assertSame(
            '\\\\ \| \{ \} \@ \[ \] \( \) \< \> \# \* \_ \~',
            $this->escape('\\ | { } @ [ ] ( ) < > # * _ ~')
        );
    }
}
