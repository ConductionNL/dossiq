<?php

/**
 * OpenCatalogiApiClient Unit Tests.
 *
 * The client no longer speaks HTTP to OpenRegister — it calls OpenRegister's
 * published in-process contract (ADR-084 `ObjectServiceInterface`) and, for
 * file bytes, `FileService`. These tests assert that migration behaviourally:
 * WHAT each operation asks OpenRegister to store, not which URL it built.
 *
 * WHY THE DOUBLES ARE HAND-WRITTEN AND NOT `createMock()`
 * ------------------------------------------------------
 * Every call this client makes into OpenRegister passes its arguments BY NAME
 * (`saveObject(object:, register:, schema:, uuid:)`), and **a PHPUnit mock
 * cannot observe named arguments** — it sees its own parameter defaults, so an
 * `expects()->with(...)` assertion passes just as well against code that never
 * passed the argument at all. A recording double that declares the real
 * parameter NAMES is the only shape that can tell those apart, so the doubles
 * below mirror `ObjectServiceInterface::saveObject()` /
 * `::findSilent()` and `FileService::addFile()` signature for signature,
 * defaults included.
 *
 * The load-bearing test here is
 * {@see OpenCatalogiApiClientTest::testUpdatePublicationMergesOverTheStoredObject()}.
 * `saveObject()` is PUT-semantic and `updateObject()` does not merge despite
 * its docblock, so a partial payload written with a bare save silently nulls
 * every property it does not name. That test seeds five stored properties,
 * sends a one-key payload, and asserts all five survive — it fails against a
 * bare save, which is exactly the defect it exists to catch.
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
 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\WooPublication;

use OCA\Procest\Service\SettingsService;
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
 * Stand-in for OpenRegister's `ObjectEntityInterface`.
 *
 * The client reads a stored object through `getObject(): array` and its
 * identifier through `getUuid(): ?string` — the two contract methods it needs.
 */
class OpenCatalogiApiClientObjectEntityDouble {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The stored property data.
	 * @param string|null $uuid The object uuid.
	 */
	public function __construct(
		private readonly array $data,
		private readonly ?string $uuid,
	) {
	}//end __construct()

	/**
	 * The stored property data.
	 *
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->data;
	}//end getObject()

	/**
	 * The object uuid.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * Recording double for OpenRegister's ObjectService.
 *
 * Signatures mirror `ObjectServiceInterface` verbatim, defaults included, so
 * the client's named arguments bind to the parameters they name and the
 * recorded values are what the client really passed.
 */
class OpenCatalogiApiClientObjectServiceDouble {

	/**
	 * Every `saveObject()` call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saveCalls = [];

	/**
	 * Every `findSilent()` call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $findCalls = [];

	/**
	 * Property data `findSilent()` hands back.
	 *
	 * @var array<string, mixed>
	 */
	public array $storedData = [];

	/**
	 * Thrown from `saveObject()` when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $saveThrows = null;

	/**
	 * Thrown from `findSilent()` when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $findThrows = null;

	/**
	 * Persist an object — mirrors ObjectServiceInterface::saveObject().
	 *
	 * @param array<string, mixed> $object The object to store.
	 * @param array<string, mixed>|null $extend Relations to expand.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param string|null $uuid The object UUID.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $silent Suppress events for this save.
	 * @param bool $_validation Validate against the schema.
	 * @param array<string, mixed>|null $uploadedFiles Files uploaded alongside the object.
	 * @param object|null $currentUser Explicit acting user.
	 * @param bool $failIfExists Fail instead of updating.
	 *
	 * @return OpenCatalogiApiClientObjectEntityDouble The stored object.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the published contract.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the published contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the published contract.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?object $currentUser = null,
		bool $failIfExists = false,
	): OpenCatalogiApiClientObjectEntityDouble {
		$this->saveCalls[] = [
			'object' => $object,
			'register' => $register,
			'schema' => $schema,
			'uuid' => $uuid,
			'_rbac' => $_rbac,
			'_multitenancy' => $_multitenancy,
		];

		if ($this->saveThrows !== null) {
			throw $this->saveThrows;
		}

		return new OpenCatalogiApiClientObjectEntityDouble(
			data: $object,
			uuid: ($uuid ?? 'generated-uuid'),
		);
	}//end saveObject()

	/**
	 * Read an object without audit — mirrors ObjectServiceInterface::findSilent().
	 *
	 * @param string $id Object id, UUID or slug.
	 * @param array<string, mixed>|null $_extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return OpenCatalogiApiClientObjectEntityDouble The object.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the published contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the published contract.
	 */
	public function findSilent(
		string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): OpenCatalogiApiClientObjectEntityDouble {
		$this->findCalls[] = [
			'id' => $id,
			'register' => $register,
			'schema' => $schema,
			'_rbac' => $_rbac,
			'_multitenancy' => $_multitenancy,
		];

		if ($this->findThrows !== null) {
			throw $this->findThrows;
		}

		return new OpenCatalogiApiClientObjectEntityDouble(data: $this->storedData, uuid: $id);
	}//end findSilent()
}//end class

/**
 * Recording double for OpenRegister's FileService.
 *
 * Mirrors the subset of `addFile()` the client uses, with the same parameter
 * names, plus `formatFile()`.
 */
class OpenCatalogiApiClientFileServiceDouble {

	/**
	 * Every `addFile()` call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $addCalls = [];

	/**
	 * Thrown from `addFile()` when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throws = null;

	/**
	 * Attach file bytes — mirrors FileService::addFile().
	 *
	 * @param object|string $objectEntity The object entity or its uuid.
	 * @param string $fileName The file name.
	 * @param mixed $content The file content.
	 * @param bool $share Whether to create a share link.
	 * @param array<int, string> $tags Tags to attach.
	 *
	 * @return object The stored file node.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the real signature.
	 */
	public function addFile(
		object|string $objectEntity,
		string $fileName,
		mixed $content,
		bool $share = false,
		array $tags = [],
	): object {
		$this->addCalls[] = [
			'objectEntity' => $objectEntity,
			'fileName' => $fileName,
			'content' => $content,
			'share' => $share,
			'tags' => $tags,
		];

		if ($this->throws !== null) {
			throw $this->throws;
		}

		return new \stdClass();
	}//end addFile()

	/**
	 * Format a stored file node — mirrors FileService::formatFile().
	 *
	 * @param object $file The stored file node.
	 *
	 * @return array<string, mixed> The file metadata.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real signature.
	 */
	public function formatFile(object $file): array {
		return ['id' => 1, 'name' => 'stored-file'];
	}//end formatFile()
}//end class

/**
 * @covers \OCA\Procest\Service\WooPublication\OpenCatalogiApiClient
 *
 * @uses   \OCA\Procest\AppInfo\Application
 * @uses   \OCA\Procest\Service\SettingsService
 */
class OpenCatalogiApiClientTest extends TestCase {

	/**
	 * The recording object-service double the client under test resolves.
	 *
	 * @var OpenCatalogiApiClientObjectServiceDouble
	 */
	private OpenCatalogiApiClientObjectServiceDouble $objectService;

	/**
	 * The recording file-service double the client under test resolves.
	 *
	 * @var OpenCatalogiApiClientFileServiceDouble
	 */
	private OpenCatalogiApiClientFileServiceDouble $fileService;

	/**
	 * Whether any HTTP client was constructed during a test.
	 *
	 * @var bool
	 */
	private bool $httpClientWasBuilt = false;

	/**
	 * Fresh doubles for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new OpenCatalogiApiClientObjectServiceDouble();
		$this->fileService = new OpenCatalogiApiClientFileServiceDouble();
		$this->httpClientWasBuilt = false;
	}//end setUp()

	/**
	 * Build the client with the recording doubles wired in.
	 *
	 * `newClient()` flips a flag rather than returning a working client, so a
	 * test can assert an object write performed NO HTTP at all — which is the
	 * whole point of the migration and cannot be shown by asserting on a URL
	 * that no longer exists.
	 *
	 * @param object|null $objectServiceOverride Replaces the object service (null = unavailable).
	 * @param object|null $fileServiceOverride Replaces the file service (null = unavailable).
	 * @param IClient|null $httpClient A working HTTP client, for the discovery tests.
	 *
	 * @return OpenCatalogiApiClient
	 */
	private function client(
		?object $objectServiceOverride = null,
		?object $fileServiceOverride = null,
		?IClient $httpClient = null,
	): OpenCatalogiApiClient {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn($objectServiceOverride);
		$settingsService->method('getFileService')->willReturn($fileServiceOverride);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturnCallback(
			function () use ($httpClient): IClient {
				$this->httpClientWasBuilt = true;
				if ($httpClient !== null) {
					return $httpClient;
				}

				return $this->createMock(IClient::class);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.nl');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new OpenCatalogiApiClient(
			clientService: $clientService,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			settingsService: $settingsService,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end client()

	/**
	 * createPublication() stores the payload through the published contract,
	 * with no uuid (a create) and with no HTTP request at all.
	 *
	 * @return void
	 */
	public function testCreatePublicationSavesThroughTheObjectContract(): void {
		$client = $this->client(objectServiceOverride: $this->objectService);

		$result = $client->createPublication('publication', 'publication', ['title' => 'Test']);

		$this->assertCount(1, $this->objectService->saveCalls);
		$call = $this->objectService->saveCalls[0];
		$this->assertSame(['title' => 'Test'], $call['object']);
		$this->assertSame('publication', $call['register']);
		$this->assertSame('publication', $call['schema']);
		$this->assertNull($call['uuid'], 'a create must not name a uuid');

		// The contract's authorisation flags must stay at their defaults —
		// ADR-022 makes them the authorisation boundary, and a double that
		// records what it received is the only way to see them.
		$this->assertTrue($call['_rbac']);
		$this->assertTrue($call['_multitenancy']);

		$this->assertFalse(
			$this->httpClientWasBuilt,
			'an object write must perform no HTTP request'
		);
		$this->assertSame('generated-uuid', $result['id']);
		$this->assertSame('Test', $result['title']);
	}//end testCreatePublicationSavesThroughTheObjectContract()

	/**
	 * attachDocument() stores the document payload against the document schema.
	 *
	 * @return void
	 */
	public function testAttachDocumentSavesAgainstTheDocumentSchema(): void {
		$client = $this->client(objectServiceOverride: $this->objectService);

		$client->attachDocument(
			'publication',
			'document',
			['title' => 'besluit.pdf', 'publication' => ['id' => 'pub-001']],
		);

		$this->assertCount(1, $this->objectService->saveCalls);
		$call = $this->objectService->saveCalls[0];
		$this->assertSame('document', $call['schema']);
		$this->assertSame(['id' => 'pub-001'], $call['object']['publication']);
		$this->assertNull($call['uuid']);
		$this->assertFalse($this->httpClientWasBuilt);
	}//end testAttachDocumentSavesAgainstTheDocumentSchema()

	/**
	 * THE LOAD-BEARING TEST: a partial update must be merged over the stored
	 * object, never written as-is.
	 *
	 * `saveObject()` is PUT-semantic, so a bare save of the one-key withdraw
	 * payload would null the title, summary, publication date, category and
	 * status of a live publication and report success. Five stored properties
	 * go in; all five must come back out.
	 *
	 * @return void
	 */
	public function testUpdatePublicationMergesOverTheStoredObject(): void {
		$this->objectService->storedData = [
			'title' => 'WOO-besluit 2026-014',
			'summary' => 'Samenvatting',
			'publicationDate' => '2026-03-01',
			'tooiCategorieUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
			'status' => 'published',
		];

		$client = $this->client(objectServiceOverride: $this->objectService);

		$client->updatePublication(
			'publication',
			'publication',
			'pub-001',
			['depublicatiedatum' => '2026-07-13T00:00:00+02:00'],
		);

		$this->assertCount(1, $this->objectService->findCalls, 'a patch must read before it writes');
		$this->assertSame('pub-001', $this->objectService->findCalls[0]['id']);
		$this->assertSame('publication', $this->objectService->findCalls[0]['register']);
		$this->assertSame('publication', $this->objectService->findCalls[0]['schema']);

		$this->assertCount(1, $this->objectService->saveCalls);
		$written = $this->objectService->saveCalls[0]['object'];

		$this->assertSame('2026-07-13T00:00:00+02:00', $written['depublicatiedatum']);
		$this->assertSame('WOO-besluit 2026-014', $written['title']);
		$this->assertSame('Samenvatting', $written['summary']);
		$this->assertSame('2026-03-01', $written['publicationDate']);
		$this->assertSame(
			'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
			$written['tooiCategorieUri']
		);
		$this->assertSame('published', $written['status']);

		// The merged object must go back to the SAME object, not create a second.
		$this->assertSame('pub-001', $this->objectService->saveCalls[0]['uuid']);
	}//end testUpdatePublicationMergesOverTheStoredObject()

	/**
	 * A key present in both the stored object and the payload takes the
	 * payload's value — the other half of the merge rule.
	 *
	 * @return void
	 */
	public function testUpdatePublicationLetsThePayloadWinOnAConflictingKey(): void {
		$this->objectService->storedData = ['status' => 'published', 'title' => 'Keep me'];

		$client = $this->client(objectServiceOverride: $this->objectService);
		$client->updatePublication('publication', 'publication', 'pub-001', ['status' => 'withdrawn']);

		$written = $this->objectService->saveCalls[0]['object'];
		$this->assertSame('withdrawn', $written['status']);
		$this->assertSame('Keep me', $written['title']);
	}//end testUpdatePublicationLetsThePayloadWinOnAConflictingKey()

	/**
	 * attachFile() attaches bytes through the in-process file service, keyed on
	 * the object id, with the base64 body passed through unchanged.
	 *
	 * @return void
	 */
	public function testAttachFileUsesTheInProcessFileService(): void {
		$client = $this->client(
			objectServiceOverride: $this->objectService,
			fileServiceOverride: $this->fileService,
		);

		$result = $client->attachFile(
			'publication',
			'document',
			'doc-001',
			'besluit.pdf',
			base64_encode('content'),
			'application/pdf',
		);

		$this->assertCount(1, $this->fileService->addCalls);
		$call = $this->fileService->addCalls[0];
		$this->assertSame('doc-001', $call['objectEntity']);
		$this->assertSame('besluit.pdf', $call['fileName']);
		$this->assertSame(base64_encode('content'), $call['content']);
		$this->assertFalse($call['share'], 'a WOO attachment must not create a public share link');
		$this->assertSame([], $call['tags']);

		$this->assertFalse($this->httpClientWasBuilt);
		$this->assertSame('stored-file', $result['name']);
	}//end testAttachFileUsesTheInProcessFileService()

	/**
	 * A thrown object service surfaces as the unchanged domain error.
	 *
	 * @return void
	 */
	public function testAnObjectWriteFailureSurfacesAsTheDomainError(): void {
		$this->objectService->saveThrows = new \Exception('database is gone');
		$client = $this->client(objectServiceOverride: $this->objectService);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$client->createPublication('publication', 'publication', []);
	}//end testAnObjectWriteFailureSurfacesAsTheDomainError()

	/**
	 * A failed read on the patch path surfaces as the same domain error, and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testAFailedPatchReadSurfacesAsTheDomainErrorAndWritesNothing(): void {
		$this->objectService->findThrows = new \Exception('not found');
		$client = $this->client(objectServiceOverride: $this->objectService);

		try {
			$client->updatePublication('publication', 'publication', 'pub-001', ['status' => 'withdrawn']);
			$this->fail('a failed read must not fall through to a write');
		} catch (RuntimeException $e) {
			$this->assertSame('opencatalogi_api_error', $e->getMessage());
		}

		$this->assertCount(
			0,
			$this->objectService->saveCalls,
			'a patch whose read failed must never save — that would be a full replace with the partial payload'
		);
	}//end testAFailedPatchReadSurfacesAsTheDomainErrorAndWritesNothing()

	/**
	 * An unavailable OpenRegister is the domain error, not a null dereference.
	 *
	 * @return void
	 */
	public function testAnUnavailableObjectServiceSurfacesAsTheDomainError(): void {
		$client = $this->client(objectServiceOverride: null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$client->createPublication('publication', 'publication', ['title' => 'Test']);
	}//end testAnUnavailableObjectServiceSurfacesAsTheDomainError()

	/**
	 * An unavailable file service is the domain error too.
	 *
	 * @return void
	 */
	public function testAnUnavailableFileServiceSurfacesAsTheDomainError(): void {
		$client = $this->client(
			objectServiceOverride: $this->objectService,
			fileServiceOverride: null,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$client->attachFile('publication', 'document', 'doc-001', 'a.pdf', base64_encode('x'), 'application/pdf');
	}//end testAnUnavailableFileServiceSurfacesAsTheDomainError()

	/**
	 * A failed file attach surfaces as the domain error.
	 *
	 * @return void
	 */
	public function testAFailedFileAttachSurfacesAsTheDomainError(): void {
		$this->fileService->throws = new \Exception('disk full');
		$client = $this->client(
			objectServiceOverride: $this->objectService,
			fileServiceOverride: $this->fileService,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opencatalogi_api_error');
		$client->attachFile('publication', 'document', 'doc-001', 'a.pdf', base64_encode('x'), 'application/pdf');
	}//end testAFailedFileAttachSurfacesAsTheDomainError()

	/**
	 * resolveCatalog() still reads OpenCatalogi's own catalog listing over HTTP
	 * and returns the first WOO-flagged catalog.
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

		$capturedUrl = null;
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')
			->with($this->callback(function (string $url) use (&$capturedUrl): bool {
				$capturedUrl = $url;
				return true;
			}), $this->anything())
			->willReturn($response);

		$client = $this->client(
			objectServiceOverride: $this->objectService,
			httpClient: $httpClient,
		);

		$catalog = $client->resolveCatalog();

		$this->assertSame('woo-verzoeken', $catalog['slug']);
		$this->assertSame(
			'https://cloud.example.nl/index.php/apps/opencatalogi/api/catalogi',
			$capturedUrl,
			'discovery reads OpenCatalogi\'s own API, not OpenRegister\'s objects API'
		);
	}//end testResolveCatalogReturnsFirstWooFlaggedCatalog()

	/**
	 * resolveCatalog() swallows a transport failure and returns null, so
	 * discovery never gates publication.
	 *
	 * @return void
	 */
	public function testResolveCatalogSwallowsTransportFailure(): void {
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willThrowException(new \Exception('connection refused'));

		$client = $this->client(
			objectServiceOverride: $this->objectService,
			httpClient: $httpClient,
		);

		$this->assertNull($client->resolveCatalog());
	}//end testResolveCatalogSwallowsTransportFailure()
}//end class
