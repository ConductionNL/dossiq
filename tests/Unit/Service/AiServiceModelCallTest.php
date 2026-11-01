<?php

/**
 * AiService model call and health check tests
 *
 * Drives the health check through the real model call up to the network:
 * an unconfigured URL, a URL the endpoint guard refuses, and a connection
 * that is refused, for a local and a cloud model.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use OCA\Dossiq\Service\Ai\AiModelIdentity;
use OCA\Dossiq\Service\Ai\AiPiiRedactor;
use OCA\Dossiq\Service\Ai\AiPromptFactory;
use OCA\Dossiq\Service\AiService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Model call and health check of AiService.
 *
 * @covers \OCA\Dossiq\Service\AiService
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 */
class AiServiceModelCallTest extends TestCase {

	/**
	 * The addresses the endpoint guard was asked about.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $guarded = [];

	/**
	 * A real AiService over this configuration and guard answer.
	 *
	 * @param array<string, string> $config  The stored app config.
	 * @param string|null           $address What the endpoint guard resolves the URL to.
	 *
	 * @return AiService The service.
	 */
	private function service(array $config, ?string $address): AiService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$guard = $this->createMock(AiEndpointGuard::class);
		$guard->method('resolveSafeAddress')->willReturnCallback(
			function (string $url, string $modelType) use ($address): ?string {
				$this->guarded[] = [$url, $modelType];
				return $address;
			}
		);

		return new AiService(
			appConfig: $appConfig,
			prompts: $this->createMock(AiPromptFactory::class),
			pii: $this->createMock(AiPiiRedactor::class),
			endpointGuard: $guard,
			audit: $this->createMock(AiAuditLog::class),
			modelIdentity: new AiModelIdentity(appConfig: $appConfig),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Without a model URL the check reports so, and asks the guard nothing.
	 *
	 * @return void
	 */
	public function testHealthReportsAMissingModelUrl(): void {
		$health = $this->service(config: [], address: null)->testHealth();

		self::assertFalse($health['healthy']);
		self::assertSame('error', $health['status']);
		self::assertSame('AI model URL is not configured', $health['message']);
		self::assertSame('local/unknown', $health['model']);
		self::assertIsInt($health['responseTimeMs']);
		self::assertSame([], $this->guarded);
	}//end testHealthReportsAMissingModelUrl()

	/**
	 * A URL the guard refuses is never connected to.
	 *
	 * @return void
	 */
	public function testHealthReportsAUrlTheGuardRefuses(): void {
		$health = $this->service(
			config: ['ai_model_url' => 'http://169.254.169.254/', 'ai_model_type' => 'cloud', 'ai_model_name' => 'gpt'],
			address: null
		)->testHealth();

		self::assertFalse($health['healthy']);
		self::assertSame('AI model URL failed SSRF security check', $health['message']);
		self::assertSame('cloud/gpt', $health['model']);
		self::assertSame([['http://169.254.169.254/', 'cloud']], $this->guarded);
	}//end testHealthReportsAUrlTheGuardRefuses()

	/**
	 * A refused connection is reported as a connection failure, for each model type.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function refusedConnections(): array {
		return [
			'local, port in the URL' => [['ai_model_url' => 'http://127.0.0.1:1/']],
			'cloud with an API key, https default port' => [['ai_model_url' => 'https://localhost/', 'ai_model_type' => 'cloud', 'ai_api_key' => 'k']],
			'cloud without an API key, http default port' => [['ai_model_url' => 'http://localhost', 'ai_model_type' => 'cloud']],
		];
	}//end refusedConnections()

	/**
	 * The model call connects to the pinned address and reports a refusal.
	 *
	 * @param array<string, string> $config The stored app config.
	 *
	 * @return void
	 *
	 * @dataProvider refusedConnections
	 */
	public function testHealthReportsARefusedConnection(array $config): void {
		$health = $this->service(config: $config, address: '127.0.0.1')->testHealth();

		self::assertFalse($health['healthy']);
		self::assertStringStartsWith('AI model connection failed: ', $health['message']);
		self::assertSame([[$config['ai_model_url'], ($config['ai_model_type'] ?? 'local')]], $this->guarded);
	}//end testHealthReportsARefusedConnection()

	/**
	 * A model that answers is reported healthy, with the model named.
	 *
	 * @return void
	 */
	public function testHealthReportsAnAnsweringModel(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'ai_model_name' ? 'mistral' : $default)
		);
		$service = new class(
			$appConfig,
			$this->createStub(AiPromptFactory::class),
			$this->createStub(AiPiiRedactor::class),
			$this->createStub(AiEndpointGuard::class),
			$this->createStub(AiAuditLog::class),
			new AiModelIdentity(appConfig: $appConfig),
			$this->createStub(LoggerInterface::class),
		) extends AiService {
			public array $sent = [];

			protected function callAiModel(string $prompt): array {
				$this->sent[] = $prompt;
				return [];
			}
		};

		$health = $service->testHealth();

		self::assertTrue($health['healthy']);
		self::assertSame('connected', $health['status']);
		self::assertSame('local/mistral', $health['model']);
		self::assertSame('Connected to local/mistral', $health['message']);
		self::assertIsInt($health['responseTimeMs']);
		self::assertSame(['Respond with "ok" to confirm connectivity.'], $service->sent);
	}//end testHealthReportsAnAnsweringModel()

	/**
	 * Model bodies and what they decode to.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function modelBodies(): array {
		return [
			'JSON answer' => ['{"response": "{\\"answer\\": \\"ja\\", \\"confidence\\": 0.9}"}', ['answer' => 'ja', 'confidence' => 0.9]],
			'plain-text answer' => ['{"response": "gewoon tekst"}', ['answer' => 'gewoon tekst', 'confidence' => 0.0]],
			'no response field' => ['{"done": true}', ['answer' => '', 'confidence' => 0.0]],
		];
	}//end modelBodies()

	/**
	 * A model body decodes to its JSON answer, or to the text as the answer.
	 *
	 * @param string               $body     The HTTP body.
	 * @param array<string, mixed> $expected The decoded suggestion.
	 *
	 * @return void
	 *
	 * @dataProvider modelBodies
	 */
	public function testAModelBodyDecodesToTheSuggestion(string $body, array $expected): void {
		$decode = new \ReflectionMethod(AiService::class, 'decodeAiModelResponse');

		self::assertSame($expected, $decode->invoke($this->service(config: [], address: null), $body));
	}//end testAModelBodyDecodesToTheSuggestion()

	/**
	 * A body that is not JSON is refused.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotJsonIsRefused(): void {
		$decode = new \ReflectionMethod(AiService::class, 'decodeAiModelResponse');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('AI model returned invalid JSON');
		$decode->invoke($this->service(config: [], address: null), '<html>');
	}//end testABodyThatIsNotJsonIsRefused()
}//end class
