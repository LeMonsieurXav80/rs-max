<?php

namespace App\Services\Browser;

use RuntimeException;

class BrowserException extends RuntimeException
{
    /** Le navigateur n'est plus connecté au compte : à traiter autrement qu'une panne. */
    public static function sessionLost(): self
    {
        return new self('Session X absente ou expirée sur le navigateur du VPS.', 401);
    }

    public function isSessionLost(): bool
    {
        return $this->getCode() === 401;
    }
}
