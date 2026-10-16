<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * Personel hesabı kaydedildi (veya şifresi yenilendi) ama geçici şifre
 * e-postası gönderilemedi (Aşama 39). Kayıt geri alınmaz; Admin "Yeni şifre
 * gönder" ile tekrar dener. Asıl hata report() ile raporlanmıştır.
 */
class InviteNotSentException extends RuntimeException {}
