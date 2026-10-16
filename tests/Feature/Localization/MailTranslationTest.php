<?php

namespace Tests\Feature\Localization;

use App\Domain\Access\Notifications\StaffAccountCreated;
use App\Models\User;
use Tests\TestCase;

/**
 * Laravel'in standart e-posta şablonundaki metinler (imza ve bağlantı notu)
 * Türkçe çıkmalı; çeviri anahtarları şablondaki metinle birebir aynı olmalı.
 */
class MailTranslationTest extends TestCase
{
    public function test_default_mail_layout_is_turkish(): void
    {
        $user = new User(['name' => 'Ayşe', 'email' => 'ayse@klinik.test']);

        $html = (string) (new StaffAccountCreated('Klinik', 'gecici123'))->toMail($user)->render();

        $this->assertStringContainsString('Saygılarımızla,', $html);
        $this->assertStringContainsString('düğmesine tıklayamıyorsanız', $html);
        $this->assertStringNotContainsString('Regards', $html);
        $this->assertStringNotContainsString('having trouble', $html);
    }
}
