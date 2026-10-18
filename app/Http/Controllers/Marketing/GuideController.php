<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\Guides;
use Illuminate\Contracts\View\View;

/**
 * Rehberler durağan içeriktir: girişsiz, arama motorlarına açık, oturumu
 * açık kullanıcı da okuyabilir.
 */
class GuideController extends Controller
{
    public function index(): View
    {
        return view('marketing.guides.index', ['guides' => Guides::all()]);
    }

    public function show(string $slug): View
    {
        $guide = Guides::find($slug) ?? abort(404);
        $others = array_values(array_filter(Guides::all(), fn (array $g) => $g['slug'] !== $slug));

        return view('marketing.guides.show', ['guide' => $guide, 'others' => array_slice($others, 0, 3)]);
    }
}
