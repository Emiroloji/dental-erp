// Kamera tarayıcısı yalnızca Hızlı İşlem ekranında kamera açıldığında yüklenir
// (ayrı bir parça olarak), diğer sayfaların yükü artmaz.
window.startBarcodeScanner = (videoElement, onResult) =>
    import('./scanner.js').then(({ startScanner }) => startScanner(videoElement, onResult));
