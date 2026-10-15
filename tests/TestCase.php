<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testler derlenmiş frontend dosyalarına (public/build) bağlı olmasın:
        // CI'daki test işi npm run build çalıştırmaz.
        $this->withoutVite();
    }
}
