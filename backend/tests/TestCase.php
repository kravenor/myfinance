<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prima di RefreshDatabase: se la config non è quella di phpunit.xml (cache, env del container)
     * i test girerebbero sul DB di sviluppo e lo svuoterebbero. Meglio fermarsi.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');

        if ($connection !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            throw new RuntimeException("I test devono usare sqlite :memory:, non «{$connection}»: controlla phpunit.xml e la cache della config.");
        }

        return parent::setUpTraits();
    }
}
