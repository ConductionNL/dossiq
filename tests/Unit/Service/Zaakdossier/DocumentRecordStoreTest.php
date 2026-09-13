<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The store's OpenRegister calls, against a stub object service that records
 * what it was asked: which schema, which filters, which uuid.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentRecordStoreTest extends TestCase {
	private const CONFIG = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'dossier_informatieobject_schema' => 'informatieobject',
		'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
	];

	/** @var object The stub object service. */
	private object $objects;

	/** @var SettingsService&MockObject The settings double answering the stub and the config. */
	private SettingsService&MockObject $settings;

	/** @var DocumentRecordStore The store under test. */
	private DocumentRecordStore $store;

	/**
	 * A store over a stub object service that records what it was asked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> uuid => row */
			public array $rows = [];
			/** @var array<int, array<string, mixed>> Every search asked. */
			public array $searches = [];
			/** @var array<int, array<string, mixed>> Every save asked. */
			public array $saves = [];
			/** @var array<int, string> Every uuid deleted. */
			public array $deleted = [];
			/** @var array<string, array<int, array<string, mixed>>> schema => rows answered to a search. */
			public array $answers = [];

			/**
			 * @param string $id The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): array {
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('not found: ' . $id);
				}

				return $this->rows[$id];
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
				$fields = array_filter($filters, static fn (string $key): bool => str_starts_with($key, '_') === false, ARRAY_FILTER_USE_KEY);
				return array_values(array_filter(
					($this->answers[$schema] ?? []),
					static function (array $row) use ($fields): bool {
						foreach ($fields as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid on an update.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$uuid = ($uuid ?? ('new-' . count($this->saves)));
				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
				$this->rows[$uuid] = ($object + ['id' => $uuid]);
				return ['id' => $uuid] + $object;
			}

			/**
			 * @param string $uuid The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return void
			 */
			public function deleteObject(string $uuid, string|int|null $register = null, string|int|null $schema = null): void {
				$this->deleted[] = $uuid;
			}
		};

		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$this->store = new DocumentRecordStore(settingsService: $this->settings);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testARecordIsFoundByItsFileIdInTheRecordSchema(): void {
		$this->objects->rows['rec-9'] = ['@self' => ['id' => 'rec-9'], 'title' => 'by uuid'];
		$this->assertSame(expected: 'by uuid', actual: $this->store->findRecord(recordId: 'rec-9')['title']);
		$this->assertNull(actual: $this->store->findRecord(recordId: 'gone'));
		$this->objects->answers['informatieobject'] = [['@self' => ['id' => 'rec-1'], 'title' => 'x', 'fileId' => 501]];

		$record = $this->store->findRecord(fileId: 501);

		$this->assertSame(expected: 'rec-1', actual: $record['id']);
		$this->assertSame(expected: ['fileId' => 501, '_limit' => 1], actual: $this->objects->searches[0]['filters']);
		$this->assertSame(expected: 'informatieobject', actual: $this->objects->searches[0]['schema']);
		$this->assertNull(actual: $this->store->findRecord(fileId: 999), message: 'no row, no record');
	}//end testARecordIsFoundByItsFileIdInTheRecordSchema()

	/**
	 * @return void
	 */
	public function testACaseIsOnlyACaseWhenTheCaseSchemaKnowsIt(): void {
		$this->objects->rows['case-1'] = ['@self' => ['id' => 'case-1'], 'title' => 'Dakkapel'];

		$this->assertSame(expected: 'case-1', actual: $this->store->findCase(caseId: 'case-1')['id']);
		$this->assertNull(actual: $this->store->findCase(caseId: 'rec-1'));
	}//end testACaseIsOnlyACaseWhenTheCaseSchemaKnowsIt()

	/**
	 * @return void
	 */
	public function testSavingARecordWithoutAnIdCreatesAndWithAnIdUpdates(): void {
		$created = $this->store->saveRecord(record: ['title' => 'new']);
		$this->assertSame(expected: 'new-0', actual: $created);
		$this->assertSame(expected: 'new-0', actual: $this->objects->saves[0]['uuid'], message: 'a create passes no uuid, so the stub minted one');

		$updated = $this->store->saveRecord(record: ['id' => 'rec-1', 'title' => 'renamed', '@self' => ['id' => 'rec-1']]);
		$this->assertSame(expected: 'rec-1', actual: $updated);
		$this->assertSame(expected: 'rec-1', actual: $this->objects->saves[1]['uuid']);
		$this->assertArrayNotHasKey(key: '@self', array: $this->objects->saves[1]['object'], message: 'the metadata block is not written back');
	}//end testSavingARecordWithoutAnIdCreatesAndWithAnIdUpdates()

	/**
	 * @return void
	 */
	public function testAJoinIsCreatedOnceAndDeletedByCase(): void {
		$this->assertTrue(condition: $this->store->ensureJoin(caseId: 'case-1', recordId: 'rec-1'));
		$join = $this->objects->saves[0];
		$this->assertSame(expected: 'zaakinformatieobject', actual: $join['schema']);
		$this->assertSame(expected: 'case-1', actual: $join['object']['case']);
		$this->assertSame(expected: 'rec-1', actual: $join['object']['informatieobject']);
		$this->assertArrayHasKey(key: 'registrationDate', array: $join['object']);

		$this->objects->answers['zaakinformatieobject'] = [['@self' => ['id' => 'join-1'], 'case' => 'case-1', 'informatieobject' => 'rec-1']];
		$this->assertFalse(condition: $this->store->ensureJoin(caseId: 'case-1', recordId: 'rec-1'), message: 'joined already');
		$this->assertCount(expectedCount: 1, haystack: $this->objects->saves);

		$this->assertSame(expected: 1, actual: $this->store->deleteJoins(recordId: 'rec-1', caseId: 'case-1'));
		$this->assertSame(expected: ['join-1'], actual: $this->objects->deleted);
		$last = end($this->objects->searches);
		$this->assertSame(expected: 'case-1', actual: $last['filters']['case']);
		$this->assertSame(expected: 'rec-1', actual: $last['filters']['informatieobject']);
	}//end testAJoinIsCreatedOnceAndDeletedByCase()

	/**
	 * @return void
	 */
	public function testTheDocumentTypeFallsBackToTheRegistersFirst(): void {
		$this->objects->rows['type-1'] = ['@self' => ['id' => 'type-1'], 'title' => 'Aanvraag', 'vertrouwelijkheidaanduiding' => 'openbaar'];
		$this->objects->answers['informatieobjecttype'] = [['@self' => ['id' => 'type-0'], 'title' => 'Aanvullend']];

		$this->assertSame(expected: 'type-1', actual: $this->store->findDocumentType(typeId: 'type-1')['id']);
		$this->assertSame(expected: 'type-0', actual: $this->store->findDocumentType(typeId: '')['id']);
		$this->assertSame(expected: 'type-0', actual: $this->store->findDocumentType(typeId: 'gone')['id'], message: 'an unknown uuid falls back too');
		$this->assertSame(expected: ['title' => 'ASC'], actual: end($this->objects->searches)['filters']['_order']);

		$this->objects->answers['informatieobjecttype'] = [];
		$this->assertSame(expected: [], actual: $this->store->findDocumentType(typeId: ''), message: 'a register without types answers none');
	}//end testTheDocumentTypeFallsBackToTheRegistersFirst()

	/**
	 * @return void
	 */
	public function testTheStoreRefusesToWorkWithoutOpenRegister(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$store = new DocumentRecordStore(settingsService: $settings);

		$this->assertNull(actual: $store->findCase(caseId: 'case-1'), message: 'a read answers nothing');
		$this->expectException(exception: RuntimeException::class);
		$store->saveRecord(record: ['title' => 'x']);
	}//end testTheStoreRefusesToWorkWithoutOpenRegister()

	/**
	 * @return void
	 */
	public function testAFolderComesFromTheFileServiceOrNotAtAll(): void {
		$this->settings->method('getFileService')->willReturn(null);
		$this->assertNull(actual: $this->store->folderOf(objectId: 'case-1'));

		$folder = $this->createMock(originalClassName: Folder::class);
		$fileService = new class ($folder) {
			/**
			 * @param Folder $folder The folder every object gets.
			 */
			public function __construct(private readonly Folder $folder) {
			}

			/**
			 * @param mixed $objectEntity The object.
			 * @param mixed $registerId The register.
			 *
			 * @return Folder The folder.
			 */
			public function getObjectFolder(mixed $objectEntity, mixed $registerId = null): Folder {
				return $this->folder;
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$store = new DocumentRecordStore(settingsService: $settings);
		$this->assertSame(expected: $folder, actual: $store->folderOf(objectId: 'case-1'));
	}//end testAFolderComesFromTheFileServiceOrNotAtAll()
}//end class
