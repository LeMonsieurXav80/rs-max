<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        $path = base_path('docs/USER-GUIDE.md');

        $content = file_exists($path)
            ? Str::markdown(file_get_contents($path))
            : '<p class="text-gray-500">Aucun guide disponible pour le moment.</p>';

        return view('help.index', compact('content'));
    }
}
