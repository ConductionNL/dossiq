<?php

/**
 * Every answer `ZrcController::destroy('zaken', …)` gives (zrc-023, REQ-CM-35).
 *
 * Characterisation for method-decomposition slice 6c: written against the
 * single destroyCase() method and run green on it before it was split.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZrcController;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A store whose find and delete answers each test sets.
 */
final class ZrcDestroyStore {
	/** @var array<int, string> */
	public array $deleted = [];

	/** @var mixed The find() answer, or a Throwable to throw. */
	public mixed $found = ['id' => 'x', 'archiefstatus' => ''];

	/** @var \Throwable|null What deleteObject() throws. */
	public ?\Throwable $deleteThrows = null;

	public function buildSearchQuery(array $requestParams, mixed $register=null, mixed $schema=null): array {
		return ['params' => $requestParams];
	}

	public function searchObjectsPaginated(array $query=[]): array {
		return ['results' => [], 'total' => 0];
	}

	public function find(int|string $id, mixed ...$args): mixed {
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}

	public function deleteObject(string $uuid): void {
		if ($this->deleteThrows !== null) {
			throw $this->deleteThrows;
		}

		$this->deleted[] = $uuid;
	}
}//end class

final class ZrcDestroyCaseTest extends TestCase {
	private const CASE_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private ZrcDestroyStore $store;

	/** @var array<string, bool> scope => granted */
	private array $scopes = [];

	private bool $storeAvailable = true;

	private bool $mapped = true;

	/** @var array<int, string> notifications published */
	private array $published = [];

	private ?\Throwable $cleanupThrows = null;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcDestroyStore();
		$this->scopes = ['zaken.verwijderen' => true, 'zaken.geforceerd-verwijderen' => false];
		$this->storeAvailable = true;
		$this->mapped = true;
		$this->published = [];
		$this->cleanupThrows = null;
	}

	private function destroy(): JSONResponse {
		$mappingService = $this->createMock(ZgwMappingService::class);
		$mappingService->method('getMapping')->willReturn(null);

		$zgwService = $this->createMock(ZgwService::class);
		$zgwService->method('validateJwtAuth')->willReturn(null);
		$zgwService->method('consumerHasScope')->willReturnCallback(
			fn (mixed $request, string $component, string $scope): bool => ($this->scopes[$scope] ?? false)
		);
		$zgwService->method('getObjectService')->willReturnCallback(fn (): ?object => $this->storeAvailable === true ? $this->store : null);
		$zgwService->method('getZgwMappingService')->willReturn($mappingService);
		$zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgwService->method('loadMappingConfig')->willReturnCallback(
			fn (): ?array => $this->mapped === true ? ['sourceRegister' => '12', 'sourceSchema' => '34'] : null
		);
		$zgwService->method('unavailableResponse')->willReturn(new JSONResponse(['detail' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE));
		$zgwService->method('mappingNotFoundResponse')->willReturn(new JSONResponse(['detail' => 'no mapping'], Http::STATUS_NOT_FOUND));
		$zgwService->method('buildBaseUrl')->willReturn('https://example.test/zaken');
		$zgwService->method('publishNotification')->willReturnCallback(
			function (string $api, string $resource, string $url, string $action): void {
				$this->published[] = $action . ' ' . $url;
			}
		);

		$relations = $this->createMock(CaseRelationService::class);
		if ($this->cleanupThrows !== null) {
			$relations->method('cleanupForDeletedCase')->willThrowException($this->cleanupThrows);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new ZrcController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			zgwService: $zgwService,
			l10n: $l10n,
			caseRelationService: $relations,
			statusEffects: $this->createMock(ZrcStatusEffects::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);

		return $controller->destroy(resource: 'zaken', uuid: self::CASE_UUID);
	}

	public function testADeleteRemovesTheZaakAndAnnouncesIt(): void {
		$response = $this->destroy();

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([self::CASE_UUID], $this->store->deleted);
		$this->assertSame(['destroy https://example.test/zaken/' . self::CASE_UUID], $this->published);
	}

	public function testWithoutTheVerwijderenScopeNothingIsDeleted(): void {
		$this->scopes['zaken.verwijderen'] = false;

		$response = $this->destroy();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
		$this->assertSame([], $this->store->deleted);
	}

	public function testAnUnavailableStoreOrMissingMappingAnswersWithThatResponse(): void {
		$this->storeAvailable = false;
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->destroy()->getStatus());

		$this->storeAvailable = true;
		$this->mapped = false;
		$this->assertSame('no mapping', $this->destroy()->getData()['detail']);
		$this->assertSame([], $this->store->deleted);
	}

	public function testAZaakThatCannotBeFoundIs404(): void {
		$this->store->found = null;
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->destroy()->getStatus());

		$this->store->found = new \RuntimeException('gone');
		$response = $this->destroy();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['detail' => 'Not found'], $response->getData());
		$this->assertSame([], $this->store->deleted);
	}

	public function testAnArchivedZaakNeedsTheGeforceerdScope(): void {
		$this->store->found = ['id' => 'x', 'archiefstatus' => 'gearchiveerd'];

		$response = $this->destroy();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('zaken.geforceerd-verwijderen', $response->getData()['detail']);
		$this->assertSame([], $this->store->deleted);

		$this->scopes['zaken.geforceerd-verwijderen'] = true;
		$this->assertSame(Http::STATUS_NO_CONTENT, $this->destroy()->getStatus());
	}

	public function testAZaakStillToArchiveNeedsNoGeforceerdScope(): void {
		$this->store->found = ['id' => 'x', 'archiefstatus' => 'nog_te_archiveren'];

		$this->assertSame(Http::STATUS_NO_CONTENT, $this->destroy()->getStatus());
	}

	public function testTheCaseDeleteGuardAnswers409(): void {
		$this->store->deleteThrows = new HookStoppedException(
			'held',
			['error' => 'case.held', 'blockedBy' => ['legal-hold'], 'message' => 'The case is under legal hold.']
		);

		$response = $this->destroy();

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('case.held', $response->getData()['error']);
		$this->assertSame('The case is under legal hold.', $response->getData()['detail']);
		$this->assertSame([], $this->published);
	}

	public function testAnyOtherStoppedOrFailedDeleteIs400(): void {
		$this->store->deleteThrows = new HookStoppedException('some other hook', ['error' => 'other']);
		$response = $this->destroy();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to delete case: some other hook', $response->getData()['detail']);

		$this->store->deleteThrows = new \RuntimeException('db down');
		$this->assertSame('Failed to delete case: db down', $this->destroy()->getData()['detail']);
		$this->assertSame([], $this->published);
	}

	public function testAFailedRelationCleanupDoesNotStopTheDelete(): void {
		$this->cleanupThrows = new \RuntimeException('relations unreadable');

		$this->assertSame(Http::STATUS_NO_CONTENT, $this->destroy()->getStatus());
		$this->assertSame([self::CASE_UUID], $this->store->deleted);
	}
}//end class
