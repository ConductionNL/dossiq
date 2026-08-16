<?php

/**
 * OpenCatalogiApiClient Unit Tests.
 *
 * Mocks OCP\Http\Client\IClientService/IClient/IResponse — the thin HTTP
 * boundary this class owns — so no real HTTP happens in tests. Asserts the
 * request shapes against OpenRegister's confirmed Objects API routes.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\WooPublication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\WooPublication;

use OCA\Procest\Service\WooPublication\OpenCatalogiApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\WooPublication\OpenCatalogiApiClient
 */
class OpenCatalogiApiClientTest extends TestCase {

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
	 * createPublication() posts to OpenRegister's confirmed objects#create route.
	 *
	 * @return void
	 */
	public function testCreatePublicationPostsToObjectsCreateRoute(): void {
		$capturedUrl = null;
		$capturedOptions = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['id' => 'pub-001', 'title' => 'Test']));

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				$this->callback(function (string $url) use (&$capturedUrl) {
					$capturedUrl = $url;
					return true;
				}),
				$this->callback(function (array $options) use (&$capturedOptions) {
					$capturedOptions = $options;
					return true;
				})
			)
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $apiClient->createPublication('publication', 'publication', ['title' => 'Test']);

		$this->assertSame('pub-001', $result['id']);
		$this->assertSame(
			'https://cloud.example.nl/index.php/apps/openregister/api/objects/publication/publication',
			$capturedUrl
		);
		$this->assertSame('Test', $capturedOptions['json']['title']);
	}//end testCreatePublicationPostsToObjectsCreateRoute()

	/**
	 * updatePublication() PATCHes the single-object route.
	 *
	 * @return void
	 */
	public function testUpdatePublicationPatchesSingleObjectRoute(): void {
		$capturedUrl = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['id' => 'pub-001']));

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('patch')
			->with($this->callback(function (string $url) use (&$capturedUrl) {
				$capturedUrl = $url;
				return true;
			}), $this->anything())
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$apiClient->updatePublication('publication', 'publication', 'pub-001', ['depublicatiedatum' => '2026-07-13']);

		$this->assertSame(
			'https://cloud.example.nl/index.php/apps/openregister/api/objects/publication/publication/pub-001',
			$capturedUrl
		);
	}//end testUpdatePublicationPatchesSingleObjectRoute()

	/**
	 * attachFile() posts name+content to the per-object files route.
	 *
	 * @return void
	 */
	public function testAttachFilePostsToFilesRoute(): void {
		$capturedUrl = null;
		$capturedOptions = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['id' => 1]));

		$client = $this->createMock(IClient::class);
		$client->method('post')
			->with(
				$this->callback(function (string $url) use (&$capturedUrl) {
					$capturedUrl = $url;
					return true;
				}),
				$this->callback(function (array $options) use (&$capturedOptions) {
					$capturedOptions = $options;
					return true;
				})
			)
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$apiClient->attachFile('publication', 'document', 'doc-001', 'besluit.pdf', base64_encode('content'), 'application/pdf');

		$this->assertSame(
			'https://cloud.example.nl/index.php/apps/openregister/api/objects/publication/document/doc-001/files',
			$capturedUrl
		);
		$this->assertSame('besluit.pdf', $capturedOptions['json']['name']);
		$this->assertSame(base64_encode('content'), $capturedOptions['json']['content']);
	}//end testAttachFilePostsToFilesRoute()

	/**
	 * resolveCatalog() swallows a transport failure and returns null (never gates publication).
	 *
	 * @return void
	 */
	public function testResolveCatalogSwallowsTransportFailure(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('connection refused'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertNull($apiClient->resolveCatalog());
	}//end testResolveCatalogSwallowsTransportFailure()

	/**
	 * resolveCatalog() returns the first hasWooSitemap catalog.
	 *
	 * @return void
	 */
	public function testResolveCatalogReturnsFirstWooFlaggedCatalog(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'results' => [
				['slug' => 'general', 'hasWooSitemap' => false],
				['slug' => 'woo-verzoeken', 'hasWooSitemap' => true],
			],
		]));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$catalog = $apiClient->resolveCatalog();

		$this->assertSame('woo-verzoeken', $catalog['slug']);
	}//end testResolveCatalogReturnsFirstWooFlaggedCatalog()

	/**
	 * A transport failure on createPublication() surfaces as a domain RuntimeException.
	 *
	 * @return void
	 */
	public function testTransportFailureThrowsDomainException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('connection refused'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$apiClient = new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $this->urlGenerator(),
			appConfig: $this->emptyAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$apiClient->createPublication('publication', 'publication', []);
	}//end testTransportFailureThrowsDomainException()
}//end class
