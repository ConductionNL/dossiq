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
 * @package  OCA\Procest\Tests\Unit\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\CaseTypeCopyService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseTypeCopyService.
 *
 * @covers \OCA\Procest\Service\CaseTypeCopyService
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
					'register' => 'procest',
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
				],
			],
			'st-1' => [
				'__schema' => 'statusType',
				'data' => ['id' => 'st-1', 'caseType' => 'ct-1', 'name' => 'Ontvangen', 'order' => 1],
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
				'data' => ['id' => 'st-99', 'caseType' => 'other-ct', 'name' => 'Ontvangen'],
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

		$service = new CaseTypeCopyService(settingsService: $this->settingsService, logger: $this->logger);
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
	}//end testCopyDeepCopiesCaseTypeAndChildren()

	/**
	 * copy() returns null when the source case type does not resolve.
	 *
	 * @return void
	 */
	public function testCopyReturnsNullWhenSourceMissing(): void {
		$store = [];
		$objectService = $this->makeObjectService(store: $store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$service = new CaseTypeCopyService(settingsService: $this->settingsService, logger: $this->logger);

		$this->assertNull($service->copy('does-not-exist'));
	}//end testCopyReturnsNullWhenSourceMissing()

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

		$service = new CaseTypeCopyService(settingsService: $this->settingsService, logger: $this->logger);
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

		$service = new CaseTypeCopyService(settingsService: $this->settingsService, logger: $this->logger);
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

		$service = new CaseTypeCopyService(settingsService: $this->settingsService, logger: $this->logger);
		$result = $service->deleteDraft('does-not-exist');

		$this->assertFalse($result['ok']);
		$this->assertSame('not_found', $result['reason']);
	}//end testDeleteDraftReportsNotFound()
}//end class
