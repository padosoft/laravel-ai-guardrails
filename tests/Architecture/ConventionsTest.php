<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Architecture;

use FilesystemIterator;
use Laravel\Ai\Contracts\Tool;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ConventionsTest extends TestCase
{
    private function srcDir(): string
    {
        return realpath(__DIR__.'/../../src') ?: __DIR__.'/../../src';
    }

    /** @return list<string> absolute paths of all src/*.php files */
    private function srcFiles(): array
    {
        $files = [];
        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->srcDir(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_every_contract_is_an_interface(): void
    {
        $contracts = glob($this->srcDir().'/Contracts/*.php') ?: [];
        self::assertNotEmpty($contracts);

        foreach ($contracts as $file) {
            $fqn = 'Padosoft\\AiGuardrails\\Contracts\\'.basename($file, '.php');
            self::assertTrue(interface_exists($fqn), "{$fqn} under src/Contracts must be an interface.");
        }
    }

    public function test_tool_decorators_implement_the_tool_contract(): void
    {
        self::assertTrue(is_subclass_of(FirewalledTool::class, Tool::class));
        self::assertTrue(is_subclass_of(ApprovalGatedTool::class, Tool::class));
    }

    public function test_audit_stores_implement_the_store_contract(): void
    {
        foreach ([NullInjectionAuditStore::class, ArrayInjectionAuditStore::class, DatabaseInjectionAuditStore::class] as $store) {
            self::assertTrue(is_subclass_of($store, InjectionAuditStore::class), "{$store} must implement InjectionAuditStore.");
        }
    }

    public function test_compose_not_couple_boundary_is_respected(): void
    {
        $violations = [];

        foreach ($this->srcFiles() as $file) {
            $content = (string) file_get_contents($file);
            $unix = str_replace('\\', '/', $file);

            // laravel-flow may only be referenced inside the src/Hitl adapter dir.
            if (str_contains($content, 'Padosoft\\LaravelFlow') && ! str_contains($unix, '/src/Hitl/')) {
                $violations[] = "{$file} references Padosoft\\LaravelFlow outside src/Hitl";
            }

            // pii-redactor may only be referenced inside the src/Output adapter dir.
            if (str_contains($content, 'Padosoft\\PiiRedactor') && ! str_contains($unix, '/src/Output/')) {
                $violations[] = "{$file} references Padosoft\\PiiRedactor outside src/Output";
            }
        }

        self::assertSame([], $violations, "compose-not-couple boundary violated:\n".implode("\n", $violations));
    }
}
