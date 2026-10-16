<?php

namespace App\Console\Commands;

use App\Domain\Access\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendDailyDigestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:send-digest';

    /**
     * @var string
     */
    protected $description = 'Kullanıcılara son özetten beri düşen okunmamış bildirimlerinin günlük e-posta özetini gönderir.';

    public function handle(DailyDigestService $digests): int
    {
        $this->info($digests->send().' özet e-postası kuyruğa alındı.');

        return self::SUCCESS;
    }
}
