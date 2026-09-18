<?php

namespace App\Domain\Organization\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Salt-okunur organizasyonda yazma denemesi. 403 olarak döner; mesaj kullanıcıya gösterilir.
 */
class ReadOnlyOrganizationException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Organizasyonunuz salt-okunur modda: kayıtları görüntüleyebilirsiniz ancak ekleme veya değişiklik yapamazsınız. Bilgi için Platform Sahibi ile iletişime geçin.');
    }
}
