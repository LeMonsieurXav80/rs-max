<?php

namespace App\Services\Browser;

/** L'action a pu aboutir : la relancer risquerait de la faire deux fois. */
class BrowserUncertainException extends BrowserException {}
