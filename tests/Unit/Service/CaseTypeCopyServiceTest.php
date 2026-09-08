<?php

/**
 * CaseTypeCopyService Unit Tests
 *
 * Covers the deep-copy contract (case type + every owned sub-schema
 * re-parented to the new id, publication/version/sibling-link fields
 * reset, source left untouched, 404-equivalent on a missing source) and
 * the guarded draft-only delete (not_found / published / happy path).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
use OCA\Dossiq\Service\CaseTypeCopyService;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseTypeCopyService.
 *
 * @covers \OCA\Dossiq\Service\CaseTypeCopyService
 *
 * @uses \OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 */
class CaseTypeCopyServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_type_schema' => 'caseType',
					'status_type_schema' => 'statusType',
					'result_type_schema' => 'resultType',
					'role_type_schema' => 'roleType',
					'property_definition_schema' => 'propertyDefinition',
					'document_type_schema' => 'documentType',
					'decision_type_schema' => 'decisionType',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * Build a shared in-memory object service fake backed by a reference to
	 * a store array, supporting find(), findAll() (schema + field
	 * filters), saveObject() (CREATE when no id present) and
	 * deleteObject().
	 *
	 * @param array<string, array{__schema: string, data: array<string, mixed>}> &$store Seed store (by reference).
	 *
	 * @return object
	 */
	private function makeObjectService(array &$store): object {
		return new class($store) {
			/**
			 * @param array<string, array{__schema: string, data: array<string, mixed>}> $store Store reference.
			 */
			public function __construct(
				private array &$store,
			) {
			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema — when given, a mismatch returns null.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$entry = ($this->store[$id] ?? null);
				if ($entry === null) {
					return null;
				}

				if ($schema !== null && $entry['__schema'] !== $schema) {
					return null;
				}

				return $entry['data'];
			}//end find()

			/**
			 * @param array<string, mixed> $config Config with `filters` (register/schema/field=>value) and `limit`.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);

				$results = [];
				foreach ($this->store as $entry) {
					if ($schema !== null && $entry['__schema'] !== $schema) {
						continue;
					}

					$match = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === true) {
							continue;
						}

						if (($entry['data'][$key] ?? null) !== $value) {
							$match = false;
							break;
						}
					}

					if ($match === true) {
						$results[] = $entry['data'];
					}
				}//end foreach

				return $results;
			}//end findAll()

			/**
			 * @param array<string, mixed> $object Object payload.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema slug — stored alongside the record.
			 *
			 * @return object
			 */
			public function saveObject(array $object, $register = null, $schema = null): object {
				$id = ($object['id'] ?? null);
				if ($id === null) {
					$id = 'generated-' . (count($this->store) + 1);
					$object['id'] = $id;
				}

				$this->store[$id] = ['__schema' => (string)$schema, 'data' => $object];

				return new class($object) implements \JsonSerializable {
					/**
					 * @param array<string, mixed> $data Object data.
					 */
					public function __construct(
						private array $data,
					) {
					}//end __construct()

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}//end jsonSerialize()
				};
			}//end saveObject()

			/**
			 * @param string $uuid Object id.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, $register = null, $schema = null): bool {
				if (isset($this->store[$uuid]) === false) {
					return false;
				}

				unset($this->store[$uuid]);
				return true;
			}//end deleteObject()
		};
	}//end makeObjectService()

	/**
	 * Seed a store with a source case type and every kind of owned child,
	 * plus one decoy child belonging to a DIFFERENT case type.
	 *
	 * @return array<string, array{__schema: string, data: array<string, mixed>}>
	 */
	private function seedStore(): array {
		return [
			'ct-1' => [
				'__schema' => 'caseType',
				'data' => [
					'id' => 'ct-1',
					'title' => 'Omgevingsvergunning regulier',
					'identifier' => 'CT-1000',
					'isDraft' => false,
					'publicationRequired' => true,
					'publicationText' => 'Published on the portal',
					'workflowDefinition' => 'workflow-v3',
					'relatedCaseTypes' => ['ct-9'],
					'subCaseTypes' => ['ct-8'],
					'initialStatus' => 'st-1',
					'version' => 2,
				],
			],
			'st-1' => [
				'__schema' => 'statusType',
				'data' => ['id' => 'st-1', 'caseType' => 'ct-1', 'name' => 'Received', 'order' => 1],
			],
			'st-2' => [
				'__schema' => 'statusType',
				'data' => ['id' => 'st-2', 'caseType' => 'ct-1', 'name' => 'Besluit', 'order' => 2],
			],
			'pd-1' => [
				'__schema' => 'propertyDefinition',
				'data' => ['id' => 'pd-1', 'caseType' => 'ct-1', 'name' => 'oppervlakte'],
			],
			'rt-1' => [
				'__schema' => 'resultType',
				'data' => ['id' => 'rt-1', 'caseType' => 'ct-1', 'name' => 'Verleend'],
			],
			'role-1' => [
				'__schema' => 'roleType',
				'data' => ['id' => 'role-1', 'caseType' => 'ct-1', 'name' => 'Behandelaar'],
			],
			'doc-1' => [
				'__schema' => 'documentType',
				'data' => ['id' => 'doc-1', 'caseType' => 'ct-1', 'name' => 'Bouwtekening'],
			],
			'dec-1' => [
				'__schema' => 'decisionType',
				'data' => ['id' => 'dec-1', 'caseType' => 'ct-1', 'name' => 'Verlenen'],
			],
			// Decoy: same schema, different (unrelated) case type.
			'st-99' => [
				'__schema' => 'statusType',
				'data' => ['id' => 'st-99', 'caseType' => 'other-ct', 'name' => 'Received'],
			],
		];
	}//end seedStore()

	/**
	 * copy() creates a new case type and re-parents every owned child,
	 * leaving the source and unrelated objects untouched.
	 *
	 * @return void
	 */
	public function testCopyDeepCopiesCaseTypeAndChildren(): void {
		$store = $this->seedStore();
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$copy = $service->copy('ct-1');

		$this->assertNotNull($copy);
		$newId = $copy['id'];
		$this->assertNotSame('ct-1', $newId);
		$this->assertSame('Copy of Omgevingsvergunning regulier', $copy['title']);
		$this->assertTrue($copy['isDraft']);
		$this->assertFalse($copy['publicationRequired']);
		$this->assertSame('', $copy['publicationText']);
		$this->assertNull($copy['workflowDefinition']);
		$this->assertSame([], $copy['relatedCaseTypes']);
		$this->assertSame([], $copy['subCaseTypes']);
		$this->assertNotSame('CT-1000', $copy['identifier']);

		// Every owned child schema was copied and re-parented.
		$childSchemas = ['statusType', 'propertyDefinition', 'resultType', 'roleType', 'documentType', 'decisionType'];
		foreach ($childSchemas as $schema) {
			$copiedForNew = array_filter(
				$store,
				static fn (array $entry): bool => $entry['__schema'] === $schema && ($entry['data']['caseType'] ?? null) === $newId
			);
			$this->assertGreaterThan(0, count($copiedForNew), "expected at least one copied {$schema}");
		}

		// Exactly 2 statusType copies were made (matching the 2 seeded for ct-1).
		$copiedStatusTypes = array_filter(
			$store,
			static fn (array $entry): bool => $entry['__schema'] === 'statusType' && ($entry['data']['caseType'] ?? null) === $newId
		);
		$this->assertCount(2, $copiedStatusTypes);

		// The decoy (different case type) was never touched or duplicated.
		$this->assertSame('other-ct', $store['st-99']['data']['caseType']);
		$stillOnlyOneDecoy = array_filter(
			$store,
			static fn (array $entry): bool => $entry['__schema'] === 'statusType' && ($entry['data']['caseType'] ?? null) === 'other-ct'
		);
		$this->assertCount(1, $stillOnlyOneDecoy);

		// The source case type is unchanged.
		$this->assertSame('ct-1', $store['ct-1']['data']['id']);
		$this->assertSame('Omgevingsvergunning regulier', $store['ct-1']['data']['title']);
		$this->assertFalse($store['ct-1']['data']['isDraft']);
		$this->assertSame(['ct-9'], $store['ct-1']['data']['relatedCaseTypes']);

		// The source's own status types are unchanged (still point at ct-1).
		$this->assertSame('ct-1', $store['st-1']['data']['caseType']);
		$this->assertSame('ct-1', $store['st-2']['data']['caseType']);

		// A duplicate starts its own chain rather than reading as version 2 of
		// the type it was copied from.
		$this->assertSame(1, $store[$newId]['data']['version']);
		$this->assertNull($store[$newId]['data']['previousVersion']);
		$this->assertNull($store[$newId]['data']['supersededBy']);

		// 🔴 The copy's initial status is its OWN copy of that status, not the
		// source's row. Left unrepointed, the copy files new cases into a
		// status belonging to another case type, and publish validation refuses
		// it with "pick the status a new case starts in" on a page that already
		// shows one.
		$initial = $store[$newId]['data']['initialStatus'];
		$this->assertNotSame('st-1', $initial);
		$this->assertSame($newId, $store[$initial]['data']['caseType']);
		$this->assertSame('Received', $store[$initial]['data']['name']);
	}//end testCopyDeepCopiesCaseTypeAndChildren()

	/**
	 * newVersion() keeps the identity and starts a draft one version on.
	 *
	 * @return void
	 */
	public function testNewVersionKeepsIdentityAndChainsBack(): void {
		$store = $this->seedStore();
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$next = $service->newVersion('ct-1');

		$this->assertNotNull($next);
		$newId = $next['id'];
		$this->assertNotSame('ct-1', $newId);

		// The same case type, later on: title and identifier are what make two
		// rows versions of ONE zaaktype rather than two unrelated ones.
		$this->assertSame('Omgevingsvergunning regulier', $next['title']);
		$this->assertSame('CT-1000', $next['identifier']);
		$this->assertSame(3, $next['version']);
		$this->assertSame('ct-1', $next['previousVersion']);
		$this->assertNull($next['supersededBy']);
		$this->assertTrue($next['isDraft']);

		// A version is the same type, so it keeps its links to related and sub
		// case types. A duplicate drops them; this is the difference.
		$this->assertSame(['ct-9'], $next['relatedCaseTypes']);
		$this->assertSame(['ct-8'], $next['subCaseTypes']);
	}//end testNewVersionKeepsIdentityAndChainsBack()

	/**
	 * 🔴 newVersion() leaves the running cases' version completely alone.
	 *
	 * This is the whole reason a version is a new object. A case carries
	 * `caseType` as the id of one specific row and `status` as a row owned by
	 * it, so the reference the case already holds IS the pin, and nothing has
	 * to be written to the case at all. If the source were edited in place
	 * instead, every running case would see the change immediately.
	 *
	 * @return void
	 */
	public function testNewVersionLeavesTheRunningVersionUntouched(): void {
		$store = $this->seedStore();
		$before = $store['ct-1']['data'];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$service->newVersion('ct-1');

		$this->assertSame($before, $store['ct-1']['data']);
		$this->assertFalse($store['ct-1']['data']['isDraft']);
		$this->assertArrayNotHasKey('supersededBy', $store['ct-1']['data']);
		$this->assertSame('ct-1', $store['st-1']['data']['caseType']);
		$this->assertSame('st-1', $store['ct-1']['data']['initialStatus']);
	}//end testNewVersionLeavesTheRunningVersionUntouched()

	/**
	 * A case type saved before the version property existed is version one,
	 * so its next version is two rather than one.
	 *
	 * @return void
	 */
	public function testNewVersionOfAnUnversionedCaseTypeIsTwo(): void {
		$store = $this->seedStore();
		unset($store['ct-1']['data']['version']);
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$next = $service->newVersion('ct-1');

		$this->assertNotNull($next);
		$this->assertSame(2, $next['version']);
	}//end testNewVersionOfAnUnversionedCaseTypeIsTwo()

	/**
	 * newVersion() returns null when the source case type does not resolve.
	 *
	 * @return void
	 */
	public function testNewVersionReturnsNullWhenSourceMissing(): void {
		$store = [];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$this->assertNull($service->newVersion('does-not-exist'));
	}//end testNewVersionReturnsNullWhenSourceMissing()

	/**
	 * copy() returns null when the source case type does not resolve.
	 *
	 * @return void
	 */
	public function testCopyReturnsNullWhenSourceMissing(): void {
		$store = [];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$this->assertNull($service->copy('does-not-exist'));
	}//end testCopyReturnsNullWhenSourceMissing()

	/**
	 * Nothing is attempted when OpenRegister is not there.
	 *
	 * Both gestures share one path, so both have to refuse: writing half a case
	 * type and answering an id nothing stands behind is worse than answering
	 * nothing, because the page navigates to whatever comes back.
	 *
	 * @return void
	 */
	public function testBothGesturesRefuseWithoutAnObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$this->assertNull($service->copy('ct-1'));
		$this->assertNull($service->newVersion('ct-1'));
		$this->assertFalse($service->deleteDraft('ct-1')['ok']);
	}//end testBothGesturesRefuseWithoutAnObjectService()

	/**
	 * A store that refuses the write answers null, not a half-made type.
	 *
	 * @return void
	 */
	public function testAFailedWriteAnswersNull(): void {
		$store = $this->seedStore();
		$objectService = new class($store) {
			/**
			 * @param array<string, array{__schema: string, data: array<string, mixed>}> $store Store reference.
			 */
			public function __construct(
				private array &$store,
			) {
			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$entry = ($this->store[$id] ?? null);
				if ($entry === null) {
					return null;
				}

				return $entry['data'];
			}//end find()

			/**
			 * @param array<string, mixed> $object Object payload.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return object
			 */
			public function saveObject(array $object, $register = null, $schema = null): object {
				throw new \RuntimeException('unwritable');
			}//end saveObject()
		};
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$this->assertNull($service->copy('ct-1'));
		$this->assertNull($service->newVersion('ct-1'));
	}//end testAFailedWriteAnswersNull()

	/**
	 * A child schema that fails to copy does not abort the rest.
	 *
	 * The case type is already written by then, and losing every remaining
	 * child over one bad row would leave a type that looks complete and is not.
	 *
	 * @return void
	 */
	public function testAChildThatFailsToCopyDoesNotAbortTheRest(): void {
		$store = $this->seedStore();
		$objectService = new class($store) {
			/**
			 * @param array<string, array{__schema: string, data: array<string, mixed>}> $store Store reference.
			 */
			public function __construct(
				private array &$store,
			) {
			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$entry = ($this->store[$id] ?? null);
				if ($entry === null) {
					return null;
				}

				return $entry['data'];
			}//end find()

			/**
			 * @param array<string, mixed> $config Config with filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);
				if ($schema === 'roleType') {
					throw new \RuntimeException('unreadable');
				}

				$results = [];
				foreach ($this->store as $entry) {
					if ($entry['__schema'] !== $schema) {
						continue;
					}

					if (($entry['data']['caseType'] ?? null) === ($filters['caseType'] ?? null)) {
						$results[] = $entry['data'];
					}
				}

				return $results;
			}//end findAll()

			/**
			 * @param array<string, mixed> $object Object payload.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				if (($schema === 'documentType') === true) {
					throw new \RuntimeException('unwritable child');
				}

				$id = ($object['id'] ?? null);
				if ($id === null) {
					$id = 'generated-' . (count($this->store) + 1);
					$object['id'] = $id;
				}

				$this->store[$id] = ['__schema' => (string)$schema, 'data' => $object];

				return $object;
			}//end saveObject()
		};
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$next = $service->newVersion('ct-1');

		$this->assertNotNull($next);
		$newId = $next['id'];

		// The statuses still copied, and the initial status still repointed:
		// an unreadable role schema and an unwritable document type are not
		// reasons to lose the lifecycle.
		$copiedStatuses = array_filter(
			$store,
			static fn (array $entry): bool => $entry['__schema'] === 'statusType' && ($entry['data']['caseType'] ?? null) === $newId
		);
		$this->assertCount(2, $copiedStatuses);
		$this->assertNotSame('st-1', $store[$newId]['data']['initialStatus']);
	}//end testAChildThatFailsToCopyDoesNotAbortTheRest()

	/**
	 * A case type whose initial status is somebody else's is left alone.
	 *
	 * There is nothing to repoint it to, and inventing one would file cases
	 * into a status the author never chose.
	 *
	 * @return void
	 */
	public function testAnInitialStatusOutsideTheTypeIsNotRepointed(): void {
		$store = $this->seedStore();
		$store['ct-1']['data']['initialStatus'] = 'st-99';
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$next = $service->newVersion('ct-1');

		$this->assertNotNull($next);
		$this->assertSame('st-99', $store[$next['id']]['data']['initialStatus']);
	}//end testAnInitialStatusOutsideTheTypeIsNotRepointed()

	/**
	 * A reference stored as an expanded object still repoints.
	 *
	 * OpenRegister answers a `$ref` as a bare id on one read and as the
	 * expanded object on another; reading only the string shape would leave the
	 * copy pointing at the source's status with no error anywhere.
	 *
	 * @return void
	 */
	public function testAnExpandedInitialStatusReferenceStillRepoints(): void {
		$store = $this->seedStore();
		$store['ct-1']['data']['initialStatus'] = ['id' => 'st-1', 'name' => 'Received'];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);

		$next = $service->newVersion('ct-1');

		$this->assertNotNull($next);
		$initial = $store[$next['id']]['data']['initialStatus'];
		$this->assertIsString($initial);
		$this->assertNotSame('st-1', $initial);
		$this->assertSame($next['id'], $store[$initial]['data']['caseType']);
	}//end testAnExpandedInitialStatusReferenceStillRepoints()

	/**
	 * deleteDraft() deletes a draft case type.
	 *
	 * @return void
	 */
	public function testDeleteDraftDeletesDraftCaseType(): void {
		$store = [
			'ct-draft' => [
				'__schema' => 'caseType',
				'data' => ['id' => 'ct-draft', 'title' => 'Testtype', 'isDraft' => true],
			],
		];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$result = $service->deleteDraft('ct-draft');

		$this->assertTrue($result['ok']);
		$this->assertArrayNotHasKey('ct-draft', $store);
	}//end testDeleteDraftDeletesDraftCaseType()

	/**
	 * deleteDraft() refuses to delete a published case type (409-equivalent).
	 *
	 * @return void
	 */
	public function testDeleteDraftBlocksPublishedCaseType(): void {
		$store = [
			'ct-pub' => [
				'__schema' => 'caseType',
				'data' => ['id' => 'ct-pub', 'title' => 'Omgevingsvergunning', 'isDraft' => false],
			],
		];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$result = $service->deleteDraft('ct-pub');

		$this->assertFalse($result['ok']);
		$this->assertSame('published', $result['reason']);
		$this->assertArrayHasKey('ct-pub', $store);
	}//end testDeleteDraftBlocksPublishedCaseType()

	/**
	 * deleteDraft() reports not_found when the case type does not resolve.
	 *
	 * @return void
	 */
	public function testDeleteDraftReportsNotFound(): void {
		$store = [];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(
			settingsService: $this->settingsService,
			store: new CaseTypeStore($this->settingsService),
			payloads: new DerivedCaseTypePayload(),
			logger: $this->logger
		);
		$result = $service->deleteDraft('does-not-exist');

		$this->assertFalse($result['ok']);
		$this->assertSame('not_found', $result['reason']);
	}//end testDeleteDraftReportsNotFound()
}//end class
