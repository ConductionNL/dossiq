<?php

/**
 * Unit tests for AiModelRequest: the model request reads its settings from
 * app config, refuses an unconfigured or unsafe model URL, builds the generate
 * body, pins the resolved address for curl, and refuses a non-2xx answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use OCA\Dossiq\Service\Ai\AiModelRequest;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Ai\AiModelRequest
 */
class AiModelRequestTest extends TestCase {

	private IAppConfig&MockObject $config;

	private AiEndpointGuard&MockObject $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->guard = $this->createMock(AiEndpointGuard::class);
	}//end setUp()

	private function request(): AiModelRequest {
		return new AiModelRequest($this->config, $this->guard);
	}//end request()

	public function testSettingsAreReadFromTheAppsConfig(): void {
		$this->config->expects($this->once())->method('getValueString')->with('dossiq', 'ai_model_name', 'llama3')->willReturn('mistral');

		$this->assertSame('mistral', $this->request()->setting('ai_model_name', 'llama3'));
	}//end testSettingsAreReadFromTheAppsConfig()

	public function testTheModelUrlMustBeConfigured(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls('http://ollama:11434', '');
		$request = $this->request();

		$this->assertSame('http://ollama:11434', $request->configuredModelUrl());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('AI model URL is not configured');
		$request->configuredModelUrl();
	}//end testTheModelUrlMustBeConfigured()

	public function testTheGenerateBodyAsksForOneJsonAnswer(): void {
		$this->assertSame(
			'{"model":"llama3","prompt":"Vat \"dit\" samen","stream":false,"format":"json"}',
			$this->request()->generateRequestBody('llama3', 'Vat "dit" samen')
		);
	}//end testTheGenerateBodyAsksForOneJsonAnswer()

	public function testTheAddressIsTheGuardsOrTheUrlIsRefused(): void {
		$this->guard->expects($this->exactly(2))->method('resolveSafeAddress')
			->with('http://ollama:11434', 'local')
			->willReturnOnConsecutiveCalls('10.0.0.5', null);
		$request = $this->request();

		$this->assertSame('10.0.0.5', $request->pinnedModelAddress('http://ollama:11434', 'local'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('AI model URL failed SSRF security check');
		$request->pinnedModelAddress('http://ollama:11434', 'local');
	}//end testTheAddressIsTheGuardsOrTheUrlIsRefused()

	public function testTheResolveEntryPinsHostAndPort(): void {
		$request = $this->request();

		$this->assertSame('ollama:11434:10.0.0.5', $request->pinnedResolveEntry('http://Ollama:11434/api', '10.0.0.5'));
		$this->assertSame('api.example.org:443:203.0.113.7', $request->pinnedResolveEntry('HTTPS://api.example.org/v1', '203.0.113.7'));
		$this->assertSame('ollama:80:10.0.0.5', $request->pinnedResolveEntry('http://ollama/api', '10.0.0.5'));
		$this->assertSame('::1:8080:::1', $request->pinnedResolveEntry('http://[::1]:8080/', '::1'));
	}//end testTheResolveEntryPinsHostAndPort()

	public function testAGenerateRequestIsOpenedOnTheEndpoint(): void {
		$handle = $this->request()->openGenerateRequest('http://ollama:11434/api/generate', '{}');

		$this->assertInstanceOf(\CurlHandle::class, $handle);
		$this->assertSame('http://ollama:11434/api/generate', curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));
		curl_close($handle);
	}//end testAGenerateRequestIsOpenedOnTheEndpoint()

	public function testTheApiKeyIsReadOnlyForACloudModel(): void {
		$this->config->expects($this->once())->method('getValueString')->with('dossiq', 'ai_api_key', '')->willReturn('');
		$request = $this->request();
		$handle = curl_init('http://ollama:11434');

		$request->sendCloudApiKey($handle, 'local');
		$request->sendCloudApiKey($handle, 'cloud');
		curl_close($handle);
	}//end testTheApiKeyIsReadOnlyForACloudModel()

	public function testAnAnswerWithoutA2xxStatusIsRefused(): void {
		$file = tempnam(sys_get_temp_dir(), 'ai-answer');
		file_put_contents($file, '{"response":"ok"}');
		$handle = curl_init('file://' . $file);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

		try {
			$this->request()->modelAnswer($handle);
			$this->fail('Expected a refusal for HTTP 0.');
		} catch (RuntimeException $e) {
			$this->assertSame('AI model returned HTTP 0', $e->getMessage());
		} finally {
			unlink($file);
		}
	}//end testAnAnswerWithoutA2xxStatusIsRefused()

	public function testAFailedConnectionIsRefusedWithCurlsError(): void {
		$handle = curl_init('file:///nonexistent/dossiq-' . bin2hex(random_bytes(4)));
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/^AI model connection failed: .+/');

		$this->request()->modelAnswer($handle);
	}//end testAFailedConnectionIsRefusedWithCurlsError()
}//end class
