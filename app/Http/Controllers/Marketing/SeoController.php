<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\Guides;
use Illuminate\Http\Response;

/**
 * robots.txt ve sitemap.xml (Aşama 52). Uygulama üretir ki site haritasının
 * tam adresi her sunucuda doğru olsun. Yalnızca girişsiz sayfalar listelenir.
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /dashboard',
            'Disallow: /login',
            'Disallow: /platform',
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = [
            [route('marketing.home'), null],
            [route('marketing.features'), null],
            [route('marketing.contact'), null],
            [route('guides.index'), null],
            ...array_map(fn (array $guide) => [route('guides.show', $guide['slug']), $guide['updated']], Guides::all()),
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $updated]) {
            $xml .= '  <url><loc>'.e($loc).'</loc>'.($updated ? '<lastmod>'.$updated.'</lastmod>' : '').'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
