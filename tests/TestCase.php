<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            // Registered so the composed PII redactor engine resolves in tests (it is a require-dev
            // dependency). Production hosts get it via Laravel package auto-discovery.
            PiiRedactorServiceProvider::class,
            AiGuardrailsServiceProvider::class,
        ];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T
     */
    protected function resolve(string $abstract): object
    {
        if ($this->app === null) {
            throw new RuntimeException('The Testbench application has not been booted.');
        }

        return $this->app->make($abstract);
    }
}
