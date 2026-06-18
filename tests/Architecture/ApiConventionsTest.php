<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Architecture;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Architecture guarantees for the HTTP API surface (Task 18): controllers return JsonResponse, every
 * route is named under the `ai-guardrails.api.` group, and the compose-not-couple boundary holds in
 * src/Http (optional vendors are reached only through the contracts/adapters).
 */
final class ApiConventionsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
    }

    public function test_every_controller_action_returns_a_json_response(): void
    {
        $files = glob(__DIR__.'/../../src/Http/*Controller.php') ?: [];
        // Guard against a moved/renamed directory silently turning this guarantee into a no-op.
        self::assertNotEmpty($files, 'No controllers found under src/Http — the scan would pass vacuously.');

        foreach ($files as $file) {
            $class = 'Padosoft\\AiGuardrails\\Http\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $returnType = $method->getReturnType();
                self::assertNotNull($returnType, "{$class}::{$method->getName()} must declare a return type");
                self::assertSame(
                    JsonResponse::class,
                    (string) $returnType,
                    "{$class}::{$method->getName()} must return a JsonResponse (enveloped)"
                );
            }
        }
    }

    public function test_all_api_routes_are_named_under_the_group_prefix(): void
    {
        $apiNames = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && str_starts_with($name, 'ai-guardrails.api.')) {
                $apiNames[] = $name;
            }
        }

        $expected = [
            'ai-guardrails.api.overview',
            'ai-guardrails.api.audit.index',
            'ai-guardrails.api.audit.trend',
            'ai-guardrails.api.audit.show',
            'ai-guardrails.api.firewall.index',
            'ai-guardrails.api.output.stats',
            'ai-guardrails.api.approvals.index',
            'ai-guardrails.api.approvals.approve',
            'ai-guardrails.api.approvals.reject',
            'ai-guardrails.api.settings.changes',
            'ai-guardrails.api.settings.show',
            'ai-guardrails.api.settings.update',
            'ai-guardrails.api.try.screen',
            'ai-guardrails.api.try.sanitize',
        ];

        sort($apiNames);
        sort($expected);
        self::assertSame($expected, $apiNames);
    }

    public function test_http_layer_does_not_reference_optional_vendors_directly(): void
    {
        $violations = [];
        $httpDir = __DIR__.'/../../src/Http';

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iter */
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($httpDir));
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            // An unreadable file would otherwise be silently skipped, hiding a boundary violation.
            self::assertNotFalse($content, "Could not read {$file->getPathname()}");
            foreach (['Padosoft\\LaravelFlow', 'Padosoft\\PiiRedactor'] as $vendor) {
                if (str_contains($content, $vendor)) {
                    $rel = str_replace($httpDir.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $violations[] = $rel." references {$vendor} (must go through a contract/adapter)";
                }
            }
        }

        self::assertSame([], $violations);
    }
}
