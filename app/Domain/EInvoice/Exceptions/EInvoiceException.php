<?php

namespace App\Domain\EInvoice\Exceptions;

use RuntimeException;

/**
 * e-Fatura entegratörü ya da fatura belgesiyle ilgili hata; mesaj kullanıcıya
 * gösterilir. API anahtarı mesaja yazılmaz.
 */
class EInvoiceException extends RuntimeException {}
