// Kamera tarayıcısı yalnızca Hızlı İşlem ekranında kamera açıldığında yüklenir
// (ayrı bir parça olarak), diğer sayfaların yükü artmaz.
window.startBarcodeScanner = (videoElement, onResult) =>
    import('./scanner.js').then(({ startScanner }) => startScanner(videoElement, onResult));

// Aşama 21 — PWA: service worker (yalnızca arayüz dosyalarını ve çevrimdışı sayfasını saklar).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
