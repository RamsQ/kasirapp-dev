<?php

namespace Tests\Unit;

use App\Exceptions\AiProviderException;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\GroqProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-api-key',
            'services.gemini.model' => 'gemini-test-model',
        ]);
    }

    public function test_it_returns_generated_text_without_exposing_the_api_key_in_the_url(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [['text' => '  Rekomendasi bisnis.  ']],
                ]],
            ]),
        ]);

        $response = app(GeminiProvider::class)->generateResponse('Analisis omzet.');

        $this->assertSame('Rekomendasi bisnis.', $response);

        $request = null;
        Http::assertSent(function (Request $sentRequest) use (&$request): bool {
            $request = $sentRequest;

            return true;
        });

        $this->assertNotNull($request);
        $this->assertTrue($request->hasHeader('x-goog-api-key', 'test-api-key'));
        $this->assertStringNotContainsString('test-api-key', $request->url());
        $this->assertStringContainsString('/v1beta/interactions', $request->url());
        $this->assertStringContainsString(
            'Analisis omzet.',
            data_get($request->data(), 'input')
        );
    }

    public function test_it_throws_a_safe_exception_when_the_api_key_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        try {
            app(GeminiProvider::class)->generateResponse('Halo');
            $this->fail('Expected AiProviderException was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertStringContainsString('belum dikonfigurasi', $exception->getMessage());
        }
    }

    public function test_it_returns_a_rate_limit_error_for_provider_quota_errors(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Quota exceeded for this project'],
            ], 429),
        ]);

        try {
            app(GeminiProvider::class)->generateResponse('Halo');
            $this->fail('Expected AiProviderException was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertStringContainsString('Kuota AI', $exception->getMessage());
        }
    }

    public function test_it_does_not_expose_upstream_error_details(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Internal provider secret detail'],
            ], 500),
        ]);

        try {
            app(GeminiProvider::class)->generateResponse('Halo');
            $this->fail('Expected AiProviderException was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame(502, $exception->status);
            $this->assertStringNotContainsString('provider secret detail', $exception->getMessage());
        }
    }

    public function test_groq_uses_bearer_auth_and_openai_compatible_payload(): void
    {
        config([
            'services.groq.key' => 'gsk-test-key',
            'services.groq.model' => 'llama-test',
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => '  Jawaban Groq.  ']]],
            ]),
        ]);

        $response = app(GroqProvider::class)->generateResponse('Analisis omzet.');

        $this->assertSame('Jawaban Groq.', $response);

        $request = null;
        Http::assertSent(function (Request $sentRequest) use (&$request): bool {
            $request = $sentRequest;

            return true;
        });

        $this->assertNotNull($request);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer gsk-test-key'));
        $this->assertStringNotContainsString('gsk-test-key', $request->url());
        $this->assertStringContainsString('/chat/completions', $request->url());
        $this->assertSame('llama-test', data_get($request->data(), 'model'));
        $this->assertSame(
            'Analisis omzet.',
            data_get($request->data(), 'messages.1.content')
        );
    }

    public function test_groq_reports_missing_key_without_calling_the_api(): void
    {
        config(['services.groq.key' => null]);

        try {
            app(GroqProvider::class)->generateResponse('Halo');
            $this->fail('Expected AiProviderException was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame(503, $exception->status);
        }

        Http::assertNothingSent();
    }

    public function test_groq_maps_rate_limit_and_hides_provider_details(): void
    {
        config(['services.groq.key' => 'gsk-test-key']);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => ['message' => 'rate_limit_error: secret internal detail'],
            ], 429),
        ]);

        try {
            app(GroqProvider::class)->generateResponse('Halo');
            $this->fail('Expected AiProviderException was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertStringContainsString('Kuota AI', $exception->getMessage());
            $this->assertStringNotContainsString('secret internal detail', $exception->getMessage());
        }
    }

    public function test_factory_falls_back_to_the_other_provider_when_primary_fails(): void
    {
        config([
            'services.ai.provider' => 'groq',
            'services.groq.key' => 'gsk-test-key',
            'services.gemini.key' => 'test-api-key',
        ]);

        Http::fake([
            // Provider utama (Groq) gagal.
            'api.groq.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'steps' => [['type' => 'model_output', 'content' => [['text' => 'Dari cadangan.']]]],
            ]),
        ]);

        $result = app(AiProviderFactory::class)->generateResponse('Halo');

        $this->assertSame('Dari cadangan.', $result['reply']);
        $this->assertSame('gemini', $result['provider']);
    }

    public function test_factory_throws_when_every_provider_fails(): void
    {
        config([
            'services.ai.provider' => 'groq',
            'services.groq.key' => 'gsk-test-key',
            'services.gemini.key' => 'test-api-key',
        ]);

        Http::fake([
            '*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->expectException(AiProviderException::class);
        app(AiProviderFactory::class)->generateResponse('Halo');
    }

    public function test_factory_reports_configured_state_without_leaking_keys(): void
    {
        config([
            'services.ai.provider' => 'groq',
            'services.groq.key' => null,
            'services.gemini.key' => 'test-api-key',
        ]);

        $factory = app(AiProviderFactory::class);

        // Provider utama belum dikonfigurasi, tapi cadangan siap dipakai.
        $this->assertTrue($factory->anyConfigured());
        $this->assertFalse($factory->provider()->isConfigured());
    }
}
