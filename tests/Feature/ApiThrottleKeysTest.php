<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiThrottleKeysTest extends TestCase
{
    /**
     * Sans préfixe, Laravel compte toutes les limites d’un utilisateur dans le même seau : les workers
     * Companion du NAS (progress, 240/min) épuisaient les 30/min des recettes locales (12/11/2026).
     */
    public function test_chaque_limite_de_debit_a_son_propre_compteur(): void
    {
        $owners = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! preg_match('/^throttle:(\d+),(\d+)(?:,(.*))?$/', $middleware, $m)) {
                    continue;
                }
                $this->assertNotEmpty($m[3] ?? '', "Limite sans préfixe sur {$route->uri()}");
                $owners[$m[3]][$m[1].','.$m[2]] = true;
            }
        }

        $this->assertNotEmpty($owners);
        foreach ($owners as $prefix => $limits) {
            $this->assertCount(1, $limits, "Le préfixe {$prefix} est partagé par des limites différentes.");
        }
    }
}
