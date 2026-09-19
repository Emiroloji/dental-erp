/*
 * Dental ERP service worker (Aşama 21 — PWA).
 *
 * Güvenlik kuralı: oturum gerektiren sayfalar (HTML) ve Livewire istekleri
 * HİÇBİR ZAMAN önbelleğe alınmaz — paylaşılan bir telefonda başka birinin stok
 * verisi görünmesin. Yalnızca adı hash'li derlenmiş arayüz dosyaları (/build),
 * ikonlar ve çevrimdışı sayfası saklanır.
 */
const VERSION = 'dental-erp-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/icons/icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Sayfalar: her zaman ağdan; bağlantı yoksa çevrimdışı sayfası (sayfa içeriği saklanmaz).
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    // Hash'li derleme dosyaları ve ikonlar değişmez: önbellekten, yoksa ağdan alıp sakla.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }
});
