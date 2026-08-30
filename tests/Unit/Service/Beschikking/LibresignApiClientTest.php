<?php

/**
 * LibresignApiClient Unit Tests.
 *
 * Mocks OCP\Http\Client\IClientService/IClient/IResponse — the thin HTTP
 * boundary this class owns — so no real HTTP happens in tests. Asserts the
 * request-signature payload shape, the status-poll route/uuid encoding, the
 * OCS envelope unwrap, and the transport-failure path.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/libresign-besluit-signing/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\LibresignApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\LibresignApiClient
 */
class LibresignApiClientTest extends TestCase {
	/**
	 * Build an IAppConfig stub with no service-account credentials configured.
	 *
	 * @return IAppConfig
	 */
	private function emptyAppConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return $appConfig;
	}//end emptyAppConfig()

	/**
	 * Build an IURLGenerator stub returning a fixed base URL.
	 *
	 * @return IURLGenerator
	 */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.nl');

		return $urlGenerator;
	}//end urlGenerator()

	/**
	 * requestSignature() posts the correct file id / name / signers and unwraps ocs.data.
	 *
	 * @return void
	 */
	public function testRequestSignatureSendsCorrectPayloadAndUnwrapsEnvelope(): void {
		$capturedUrl = null;
		$capturedOptions = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'ocs' => ['data' => ['uuid' => 'req-123', 'status' => 1]],
		]));

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with($this->callback(function (string $url) use (&$capturedUrl) {
				$capturedUrl = $url;
				return true;
			}), $this->callback(function (array $options) use (&$capturedOptions) {
				$capturedOptions = $options;
				return true;
			}))
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new LibresignApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$signers = [['identify' => ['email' => 'j.jansen@example.nl'], 'displayName' => 'J. Jansen']];
		$result = $apiClient->requestSignature(fileId: 12345, documentName: 'beschikking-12345', signers: $signers);

		$this->assertSame('req-123', $result['uuid']);
		$this->assertSame('https://cloud.example.nl/ocs/v2.php/apps/libresign/api/v1/request-signature', $capturedUrl);
		$this->assertSame(12345, $capturedOptions['json']['file']['fileId']);
		$this->assertSame('beschikking-12345', $capturedOptions['json']['name']);
		$this->assertSame($signers, $capturedOptions['json']['users']);
		$this->assertSame('true', $capturedOptions['headers']['OCS-APIREQUEST']);
	}//end testRequestSignatureSendsCorrectPayloadAndUnwrapsEnvelope()

	/**
	 * getStatus() GETs the validate-by-uuid route with the uuid URL-encoded.
	 *
	 * @return void
	 */
	public function testGetStatusRequestsCorrectRoute(): void {
		$capturedUrl = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'ocs' => ['data' => ['uuid' => 'req-123', 'status' => 3, 'statusText' => 'signed']],
		]));

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with($this->callback(function (string $url) use (&$capturedUrl) {
				$capturedUrl = $url;
				return true;
			}), $this->anything())
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new LibresignApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $apiClient->getStatus('req-123');

		$this->assertSame('signed', $result['statusText']);
		$this->assertSame(
			'https://cloud.example.nl/ocs/v2.php/apps/libresign/api/v1/file/validate/uuid/req-123',
			$capturedUrl
		);
	}//end testGetStatusRequestsCorrectRoute()

	/**
	 * Configured service-account credentials are sent as HTTP Basic auth.
	 *
	 * @return void
	 */
	public function testServiceAccountCredentialsAreSentAsBasicAuth(): void {
		$capturedOptions = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['ocs' => ['data' => ['uuid' => 'req-1']]]));

		$client = $this->createMock(IClient::class);
		$client->method('post')
			->with($this->anything(), $this->callback(function (array $options) use (&$capturedOptions) {
				$capturedOptions = $options;
				return true;
			}))
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'libresign_service_uid') {
					return 'svc-account';
				}

				if ($key === 'libresign_service_app_password') {
					return 'secret-app-password';
				}

				return $default;
			}
		);

		$apiClient = new LibresignApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		$apiClient->requestSignature(fileId: 1, documentName: 'x', signers: []);

		$this->assertSame(['svc-account', 'secret-app-password'], $capturedOptions['auth']);
	}//end testServiceAccountCredentialsAreSentAsBasicAuth()

	/**
	 * A transport failure surfaces as a domain RuntimeException, never a raw exception.
	 *
	 * @return void
	 */
	public function testTransportFailureThrowsDomainException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('connection refused'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new LibresignApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_api_error');
		$apiClient->requestSignature(fileId: 1, documentName: 'x', signers: []);
	}//end testTransportFailureThrowsDomainException()

	/**
	 * A malformed/unexpected envelope (missing ocs.data) throws the same domain exception.
	 *
	 * @return void
	 */
	public function testMissingOcsDataEnvelopeThrowsDomainException(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['unexpected' => true]));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new LibresignApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_api_error');
		$apiClient->getStatus('req-1');
	}//end testMissingOcsDataEnvelopeThrowsDomainException()
}//end class
