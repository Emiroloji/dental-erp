<?php

namespace App\Domain\Platform\Contracts;

use App\Domain\Platform\Exceptions\BackupException;
use Carbon\CarbonImmutable;

/**
 * Aşama 30 — yedeğin sunucu dışına kopyalanması.
 *
 * ErrorReporter ve QueryInterpreter ile aynı yaklaşım: uygulama hedefin ne
 * olduğunu bilmez. Bugün rclone üzerinden Google Drive kullanılıyor; yarın
 * S3'e geçilirse yalnızca yeni bir uygulama yazılır, çağıran kod değişmez.
 */
interface OffsiteBackupSync
{
    /**
     * Hedef tanımlı ve kullanılabilir durumda mı.
     */
    public function isConfigured(): bool;

    /**
     * Hedefin insan okunur adı — log ve komut çıktısında gösterilir.
     */
    public function describe(): string;

    /**
     * Yerel yedek klasörünü hedefe kopyalar.
     *
     * @return array<int, string> hedefe yeni kopyalanan dosya adları
     *
     * @throws BackupException kopyalama başarısız olursa
     */
    public function push(): array;

    /**
     * Hedefteki en yeni yedeğin zamanı; hiç yedek yoksa null.
     *
     * @throws BackupException hedefe ulaşılamazsa
     */
    public function latestBackupAt(): ?CarbonImmutable;
}
