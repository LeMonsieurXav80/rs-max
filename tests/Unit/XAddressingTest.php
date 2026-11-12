<?php

namespace Tests\Unit;

use App\Services\Agent\XAddressing;
use PHPUnit\Framework\TestCase;

class XAddressingTest extends TestCase
{
    public function test_le_role_du_compte_vient_du_parent_de_la_cible_et_de_la_branche(): void
    {
        $origin = ['id' => '1', 'author' => 'Owner', 'text' => 'Ma publication'];
        $toOwner = ['id' => '2', 'author' => 'bob', 'text' => 'Superbe !'];
        $this->assertSame(XAddressing::ADDRESSED, XAddressing::describe('owner', [$origin, $toOwner])['role']);

        $toBob = ['id' => '3', 'author' => 'carol', 'text' => '@bob je suis d’accord'];
        $described = XAddressing::describe('@owner', [$origin, $toOwner, $toBob]);
        $this->assertSame(XAddressing::PARTICIPANT, $described['role']);
        $this->assertSame('@bob', $described['repond_a']);

        $continued = ['id' => '4', 'author' => 'bob', 'text' => 'Et aussi la couleur.'];
        $this->assertSame(XAddressing::ADDRESSED, XAddressing::describe('owner', [$origin, $toOwner, $continued])['role']);
        $selfThread = [['id' => '1', 'author' => 'alice', 'text' => 'Fil 1/2'], ['id' => '2', 'author' => 'alice', 'text' => 'Fil 2/2']];
        $this->assertSame(XAddressing::OUTSIDER, XAddressing::describe('owner', $selfThread)['role']);

        $foreign = [['id' => '1', 'author' => 'alice', 'text' => 'Mon post'], ['id' => '2', 'author' => 'bob', 'text' => 'Bravo']];
        $this->assertSame(XAddressing::OUTSIDER, XAddressing::describe('owner', $foreign)['role']);
        // La cible peut arriver à part, la branche s’arrêtant à son parent.
        $this->assertSame(XAddressing::OUTSIDER, XAddressing::describe('owner', [$foreign[0]], $foreign[1])['role']);
        $named = ['id' => '3', 'author' => 'bob', 'text' => 'Qu’en pense @Owner ?'];
        $this->assertSame(XAddressing::ADDRESSED, XAddressing::describe('owner', [...$foreign, $named])['role']);
        $this->assertSame(XAddressing::OUTSIDER, XAddressing::describe('owner', [...$foreign, ['text' => '@owner_fan oui'] + $named])['role']);
    }

    public function test_un_remerciement_n_est_permis_qu_au_compte_interpelle(): void
    {
        $this->assertFalse(XAddressing::fits(XAddressing::OUTSIDER, 'Merci beaucoup, c’est gentil !'));
        $this->assertFalse(XAddressing::fits(XAddressing::PARTICIPANT, '🙏 Thanks!'));
        $this->assertTrue(XAddressing::fits(XAddressing::ADDRESSED, 'Merci beaucoup !'));
        $this->assertTrue(XAddressing::fits(XAddressing::OUTSIDER, 'Mercier a raison sur ce point.'));
        $this->assertTrue(XAddressing::fits(null, 'Merci !'));
    }
}
