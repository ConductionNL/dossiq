<?php

/**
 * OpenCatalogiApiClient return-shape and discovery-branch tests.
 *
 * The companion {@see OpenCatalogiApiClientTest} covers the happy write paths
 * and the transport failures. This file pins the branches it leaves open:
 * what the client does with each SHAPE OpenRegister may hand back (array,
 * entity, unreadable value, missing identifier), what it does with each shape
 * of OpenCatalogi's catalog listing, and that the configured service account
 * is sent on the discovery call.
 *
 * The object/file doubles declare the real parameter names, for the reason
 * given in the companion file: the client passes every argument by name.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Publication\OpenCatalogiApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Object service double that hands back whatever value a test configures.
 */
class OpenCatalogiShapesObjectServiceDouble {

	/**
	 * Constructor.
	 *
	 * @param mixed $saveReturns What saveObject() returns.
	 * @param mixed $findReturns What findSilent() returns.
	 */
	public function __construct(
		public mixed $saveReturns = null,
		public mixed $findReturns = null,
	) {
	}//end __construct()

	/**
	 * Every object saveObject() received, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Persist an object — mirrors the named parameters the client passes.
	 *
	 * @param array<string, mixed> $object The object to store.
	 * @param array<string, mixed>|null $extend Relations to expand.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param string|null $uuid The object UUID.
	 *
	 * @return mixed The configured return value.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the published contract.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): mixed {
		$this->saved[] = $object;
		return $this->saveReturns;
	}//end saveObject()

	/**
	 * Read an object — mirrors the named parameters the client passes.
	 *
	 * @param string $id Object id.
	 * @param array<string, mixed>|null $_extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 *
	 * @return mixed The configured return value.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the published contract.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the published contract.
	 */
	public function findSilent(
		string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
	): mixed {
		return $this->findReturns;
	}//end findSilent()
}//end class

/**
 * Entity double whose getObject() and getUuid() return configured values.
 */
class OpenCatalogiShapesEntityDouble {

	/**
	 * Constructor.
	 *
	 * @param mixed $data What getObject() returns.
	 * @param mixed $uuid What getUuid() returns.
	 */
	public function __construct(
		private readonly mixed $data,
		private readonly mixed $uuid,
	) {
	}//end __construct()

	/**
	 * The stored property data.
	 *
	 * @return mixed
	 */
	public function getObject(): mixed {
		return $this->data;
	}//end getObject()

	/**
	 * The object uuid.
	 *
	 * @return mixed
	 */
	public function getUuid(): mixed {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * File service double whose formatFile() returns a configured value.
 */
class OpenCatalogiShapesFileServiceDouble {

	/**
	 * Constructor.
	 *
	 * @param mixed $formatted What formatFile() returns.
	 */
	public function __construct(
		private readonly mixed $formatted,
	) {
	}//end __construct()

	/**
	 * Attach file bytes — mirrors the named parameters the client passes.
	 *
	 * @param object|string $objectEntity The object entity or its uuid.
	 * @param string $fileName The file name.
	 * @param mixed $content The file content.
	 * @param bool $share Whether to create a share link.
	 * @param array<int, string> $tags Tags to attach.
	 *
	 * @return object The stored file node.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real signature.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the real signature.
	 */
	public function addFile(
		object|string $objectEntity,
		string $fileName,
		mixed $content,
		bool $share = false,
		array $tags = [],
	): object {
		return new \stdClass();
	}//end addFile()

	/**
	 * Format a stored file node.
	 *
	 * @param object $file The stored file node.
	 *
	 * @return mixed The configured value.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real signature.
	 */
	public function formatFile(object $file): mixed {
		return $this->formatted;
	}//end formatFile()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Publication\OpenCatalogiApiClient
 *
 * @uses   \OCA\Dossiq\AppInfo\Application
 * @uses   \OCA\Dossiq\Service\SettingsService
 */
class OpenCatalogiApiClientShapesTest extends TestCase {

	/**
	 * The logger the client under test writes to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh logger for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the client.
	 *
	 * @param object|null $objectService The object service (null = unavailable).
	 * @param object|null $fileService The file service (null = unavailable).
	 * @param IClient|null $httpClient The HTTP client for discovery.
	 * @param array<string, string> $config App config values by key.
	 *
	 * @return OpenCatalogiApiClient
	 */
	private function client(
		?object $objectService = null,
		?object $fileService = null,
		?IClient $httpClient = null,
		array $config = [],
	): OpenCatalogiApiClient {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn($objectService);
		$settingsService->method('getFileService')->willReturn($fileService);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($httpClient ?? $this->createMock(IClient::class));

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.nl/');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			settingsService: $settingsService,
			logger: $this->logger,
		);
	}//end client()

	/**
	 * An HTTP client answering every GET with the given body.
	 *
	 * @param string $body The response body.
	 *
	 * @return IClient&MockObject
	 */
	private function httpReturning(string $body): IClient&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		return $httpClient;
	}//end httpReturning()

	/**
	 * An array returned by OpenRegister is the data, and its `id` is kept.
	 *
	 * @return void
	 */
	public function testAnArrayReturnIsTheDataAndItsIdIsKept(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(
			saveReturns: ['id' => 'pub-9', 'title' => 'Besluit'],
		);

		$result = $this->client(objectService: $objectService)->createPublication('publication', 'publication', ['title' => 'Besluit']);

		$this->assertSame(['id' => 'pub-9', 'title' => 'Besluit'], $result);
	}//end testAnArrayReturnIsTheDataAndItsIdIsKept()

	/**
	 * An array carrying only `uuid` gets that uuid as its `id`.
	 *
	 * @return void
	 */
	public function testAnArrayReturnWithOnlyAUuidGetsItAsId(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(
			saveReturns: ['uuid' => 'abc-1', 'title' => 'Besluit'],
		);

		$result = $this->client(objectService: $objectService)->createPublication('publication', 'publication', []);

		$this->assertSame(['uuid' => 'abc-1', 'title' => 'Besluit', 'id' => 'abc-1'], $result);
	}//end testAnArrayReturnWithOnlyAUuidGetsItAsId()

	/**
	 * An entity with an empty uuid adds no `id` key at all.
	 *
	 * @return void
	 */
	public function testAnEntityWithAnEmptyUuidAddsNoId(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(
			saveReturns: new OpenCatalogiShapesEntityDouble(data: ['title' => 'Besluit'], uuid: ''),
		);

		$result = $this->client(objectService: $objectService)->attachDocument('publication', 'document', []);

		$this->assertSame(['title' => 'Besluit'], $result);
	}//end testAnEntityWithAnEmptyUuidAddsNoId()

	/**
	 * An array whose id is not a string adds no `id` from it.
	 *
	 * @return void
	 */
	public function testAnArrayWithANonStringIdIsLeftAsIs(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(saveReturns: ['id' => 42]);

		$result = $this->client(objectService: $objectService)->createPublication('publication', 'publication', []);

		$this->assertSame(['id' => 42], $result);
	}//end testAnArrayWithANonStringIdIsLeftAsIs()

	/**
	 * A scalar return is unreadable: the domain error, with a warning naming its type.
	 *
	 * @return void
	 */
	public function testAnUnreadableReturnIsTheDomainError(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(saveReturns: 'saved');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'OpenCatalogiApiClient: OpenRegister returned an unreadable object shape',
				$this->callback(static fn (array $context): bool => $context['type'] === 'string')
			);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$this->client(objectService: $objectService)->createPublication('publication', 'publication', []);
	}//end testAnUnreadableReturnIsTheDomainError()

	/**
	 * An entity whose getObject() is not an array is unreadable too.
	 *
	 * @return void
	 */
	public function testAnEntityWithNonArrayDataIsTheDomainError(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(
			saveReturns: new OpenCatalogiShapesEntityDouble(data: null, uuid: 'x'),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$this->client(objectService: $objectService)->createPublication('publication', 'publication', []);
	}//end testAnEntityWithNonArrayDataIsTheDomainError()

	/**
	 * An object without getObject() is unreadable on the patch read, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnreadablePatchReadWritesNothing(): void {
		$objectService = new OpenCatalogiShapesObjectServiceDouble(findReturns: new \stdClass());

		try {
			$this->client(objectService: $objectService)->updatePublication('publication', 'publication', 'pub-1', ['a' => 1]);
			$this->fail('an unreadable stored object must not fall through to a write');
		} catch (RuntimeException $e) {
			$this->assertSame('opencatalogi_api_error', $e->getMessage());
		}

		$this->assertSame([], $objectService->saved);
	}//end testAnUnreadablePatchReadWritesNothing()

	/**
	 * attachFile() answers an empty array when the file service formats to a non-array.
	 *
	 * @return void
	 */
	public function testAttachFileAnswersEmptyWhenTheFormatIsNotAnArray(): void {
		$client = $this->client(
			objectService: new OpenCatalogiShapesObjectServiceDouble(),
			fileService: new OpenCatalogiShapesFileServiceDouble(formatted: null),
		);

		$this->assertSame([], $client->attachFile('p', 'd', 'doc-1', 'a.pdf', base64_encode('x'), 'application/pdf'));
	}//end testAttachFileAnswersEmptyWhenTheFormatIsNotAnArray()

	/**
	 * A listing under `data` is read like one under `results`.
	 *
	 * @return void
	 */
	public function testResolveCatalogReadsTheDataKey(): void {
		$http = $this->httpReturning(json_encode(['data' => [['slug' => 'woo', 'hasWooSitemap' => true]]]));

		$this->assertSame(['slug' => 'woo', 'hasWooSitemap' => true], $this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogReadsTheDataKey()

	/**
	 * A bare list is itself the listing.
	 *
	 * @return void
	 */
	public function testResolveCatalogReadsABareList(): void {
		$http = $this->httpReturning(json_encode([
			'not-a-catalog',
			['slug' => 'algemeen'],
			['slug' => 'woo', 'hasWooSitemap' => true],
		]));

		$this->assertSame(['slug' => 'woo', 'hasWooSitemap' => true], $this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogReadsABareList()

	/**
	 * A non-array `results` value finds nothing.
	 *
	 * @return void
	 */
	public function testResolveCatalogWithANonArrayListingIsNull(): void {
		$http = $this->httpReturning(json_encode(['results' => 'none']));

		$this->assertNull($this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogWithANonArrayListingIsNull()

	/**
	 * Only `hasWooSitemap === true` counts: a truthy string does not.
	 *
	 * @return void
	 */
	public function testResolveCatalogWithoutAWooCatalogIsNull(): void {
		$http = $this->httpReturning(json_encode(['results' => [
			['slug' => 'a', 'hasWooSitemap' => false],
			['slug' => 'b', 'hasWooSitemap' => 'true'],
		]]));

		$this->assertNull($this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogWithoutAWooCatalogIsNull()

	/**
	 * A body that is not JSON is null, logged once as info and not as a transport warning.
	 *
	 * @return void
	 */
	public function testResolveCatalogWithAnUndecodableBodyIsNullWithoutATransportWarning(): void {
		$http = $this->httpReturning('<html>login</html>');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('discovery call failed'),
				$this->callback(static fn (array $context): bool => $context['error'] === 'opencatalogi_api_error')
			);

		$this->assertNull($this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogWithAnUndecodableBodyIsNullWithoutATransportWarning()

	/**
	 * A transport failure is logged as a warning before discovery gives up.
	 *
	 * @return void
	 */
	public function testResolveCatalogTransportFailureLogsAWarning(): void {
		$http = $this->createMock(IClient::class);
		$http->method('get')->willThrowException(new \Exception('timeout'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'OpenCatalogiApiClient: catalog discovery request failed',
				$this->callback(static fn (array $context): bool => $context['error'] === 'timeout'
					&& $context['url'] === 'https://cloud.example.nl/index.php/apps/opencatalogi/api/catalogi')
			);

		$this->assertNull($this->client(httpClient: $http)->resolveCatalog());
	}//end testResolveCatalogTransportFailureLogsAWarning()

	/**
	 * A configured service account is sent as basic auth, with the JSON headers and timeout.
	 *
	 * @return void
	 */
	public function testDiscoverySendsTheConfiguredServiceAccount(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('[]');

		$http = $this->createMock(IClient::class);
		$http->expects($this->once())
			->method('get')
			->with(
				'https://cloud.example.nl/index.php/apps/opencatalogi/api/catalogi',
				[
					'timeout' => 15,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'auth' => ['svc-woo', 'app-pass'],
				]
			)
			->willReturn($response);

		$client = $this->client(
			httpClient: $http,
			config: ['opencatalogi_service_uid' => 'svc-woo', 'opencatalogi_service_app_password' => 'app-pass'],
		);

		$this->assertNull($client->resolveCatalog());
	}//end testDiscoverySendsTheConfiguredServiceAccount()

	/**
	 * A uid without a password sends no auth at all.
	 *
	 * @return void
	 */
	public function testDiscoveryWithoutAPasswordSendsNoAuth(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('[]');

		$http = $this->createMock(IClient::class);
		$http->expects($this->once())
			->method('get')
			->with(
				$this->anything(),
				$this->callback(static fn (array $options): bool => array_key_exists('auth', $options) === false)
			)
			->willReturn($response);

		$this->client(httpClient: $http, config: ['opencatalogi_service_uid' => 'svc-woo'])->resolveCatalog();
	}//end testDiscoveryWithoutAPasswordSendsNoAuth()
}//end class
