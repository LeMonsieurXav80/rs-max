<?php

namespace Tests\Unit;

use App\Services\Agent\XReplyStyle;
use Tests\TestCase;

class XReplyStyleTest extends TestCase
{
    public function test_ni_seduction_ni_relance_sans_question(): void
    {
        $this->assertNotNull(XReplyStyle::violation('La robe a un petit côté irrésistible ! 🥰', 'Superbe robe'));
        $this->assertNotNull(XReplyStyle::violation('Merci ❤️', 'Bravo'));
        $this->assertNotNull(XReplyStyle::violation('La robe a vraiment un petit quelque chose, hein ? 😄✨', 'Jolie robe'));
        $this->assertNull(XReplyStyle::violation('Merci, c’est gentil ! 😊', 'Jolie robe'));
        $this->assertNull(XReplyStyle::violation('Le bleu marine. Tu cherches pour quelle occasion ?', 'Tu conseilles quelle couleur ?'));
    }
}
