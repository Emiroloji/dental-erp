<?php

namespace App\Support;

/**
 * Tanıtım sitesindeki ÜTS ve e-fatura rehberleri (Aşama 52). Her rehber
 * resources/guides/{slug}.php içinde veri olarak durur ve yalnızca resmî bir
 * kaynağa (ÜTS Web Servis Tanımları, GİB kılavuzları) dayanır; kaynağı
 * gösterilemeyen kural yazılmaz. Sıra dosyadaki 'order' alanıyla verilir.
 */
final class Guides
{
    /**
     * @return list<array{slug: string, title: string, description: string, summary: string, updated: string, order: int, sections: list<array{heading: string, paragraphs?: list<string>, items?: list<string>, steps?: bool}>, program: string, sources: list<array{0: string, 1: string}>}>
     */
    public static function all(): array
    {
        $guides = array_map(fn (string $file) => require $file, glob(resource_path('guides/*.php')) ?: []);
        usort($guides, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $guides;
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $guide) {
            if ($guide['slug'] === $slug) {
                return $guide;
            }
        }

        return null;
    }
}
