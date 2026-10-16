<?php

namespace App\Domain\Import\Exceptions;

use RuntimeException;

/**
 * Dosya düzeyinde içe aktarma hatası: okunamayan dosya, eksik sütun, boş
 * dosya veya satır sınırı. Satır hataları bu istisnayla değil ImportRow
 * üzerinden bildirilir.
 */
class ImportFileException extends RuntimeException {}
