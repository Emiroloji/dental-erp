import { BrowserMultiFormatReader } from '@zxing/browser';

/**
 * Telefon/bilgisayar kamerasıyla barkod ve QR okuma (Aşama 20). EAN-13,
 * Code128 gibi ürün barkodlarını ve sistemin bastığı QR etiketlerini okur.
 * İlk okumada geri çağırır; dönen nesnenin stop() metodu kamerayı kapatır.
 */
export async function startScanner(videoElement, onResult) {
    const reader = new BrowserMultiFormatReader();
    let done = false;

    const controls = await reader.decodeFromVideoDevice(undefined, videoElement, (result) => {
        if (result && ! done) {
            done = true;
            onResult(result.getText());
        }
    });

    return controls;
}
