<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A document joined from another case's folder is a linked row; one in this
 * case's folder is not, the browser already shows it.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\LinkedDocumentsReader;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinkedDocumentsReaderTest extends TestCase {
	private const CASE_B = 'case-b';
	private const CASE_A = 'case-a';

	/** @var DocumentRecordStore&MockObject The store double. */
	private DocumentRecordStore&MockObject $store;

	/** @var DocumentProjectionService&MockObject The projection double, for the case folder. */
	private DocumentProjectionService&MockObject $projection;

	/** @var object The stub object service answering the joins of case B. */
	private object $objects;

	/** @var LinkedDocumentsReader The reader under test. */
	private LinkedDocumentsReader $reader;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> The joins of the case asked. */
			public array $joins = [];

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The joins.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->joins;
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default)
		);
		$this->store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$this->projection = $this->createMock(originalClassName: DocumentProjectionService::class);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => '/r/' . $route . '/' . implode('/', array_map('strval', $params))
		);
		$this->reader = new LinkedDocumentsReader(
			settingsService: $settings,
			store: $this->store,
			projection: $this->projection,
			urlGenerator: $urls,
		);
	}//end setUp()

	/**
	 * A folder double that holds the given file ids.
	 *
	 * @param array<int> $fileIds The ids it holds.
	 *
	 * @return Folder&MockObject The folder.
	 */
	private function folderHolding(array $fileIds): Folder&MockObject {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getById')->willReturnCallback(
			function (int $fileId) use ($fileIds): array {
				if (in_array($fileId, $fileIds, true) === true) {
					return [$this->createMock(originalClassName: Node::class)];
				}

				return [];
			}
		);
		return $folder;
	}//end folderHolding()

	/**
	 * @return void
	 */
	public function testADocumentInAnotherCasesFolderIsALinkedRowNamingThatCase(): void {
		$this->objects->joins = [
			['informatieobject' => 'rec-own', 'case' => self::CASE_B],
			['informatieobject' => 'rec-linked', 'case' => self::CASE_B],
		];
		$this->projection->method('folderOf')->with(self::CASE_B)->willReturn($this->folderHolding(fileIds: [100]));
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => match ($recordId) {
				'rec-own' => ['id' => 'rec-own', 'fileName' => 'own.pdf', 'fileId' => 100],
				'rec-linked' => ['id' => 'rec-linked', 'fileName' => 'besluit.pdf', 'format' => 'application/pdf', 'bestandsomvang' => 1024, 'fileId' => 200],
				default => null,
			}
		);
		$this->store->method('joinsFor')->with('rec-linked')->willReturn([
			['case' => self::CASE_B, 'registrationDate' => '2026-03-02T10:00:00Z'],
			['case' => self::CASE_A, 'registrationDate' => '2026-03-01T10:00:00Z'],
		]);
		$this->store->method('findCase')->with(self::CASE_A)->willReturn(['id' => self::CASE_A, 'identifier' => '2026-0042', 'title' => 'Dakkapel']);

		$items = $this->reader->linkedDocuments(caseId: self::CASE_B);

		$this->assertCount(expectedCount: 1, haystack: $items, message: 'the document in this case\'s folder is not a linked row');
		$this->assertSame(expected: 'rec-linked', actual: $items[0]['id']);
		$this->assertSame(expected: 'besluit.pdf', actual: $items[0]['name']);
		$this->assertSame(expected: 'application/pdf', actual: $items[0]['mime']);
		$this->assertSame(expected: 1024, actual: $items[0]['size']);
		$this->assertSame(expected: '/r/files.viewcontroller.showFile/200', actual: $items[0]['href']);
		$this->assertSame(expected: '/r/dossiq.zaakdossierDownload.downloadZgwDocumenten/rec-linked', actual: $items[0]['downloadHref']);
		$this->assertSame(expected: 'In 2026-0042', actual: $items[0]['note']);
		$this->assertSame(expected: '/r/dossiq.dashboard.page/cases/case-a', actual: $items[0]['noteHref']);
	}//end testADocumentInAnotherCasesFolderIsALinkedRowNamingThatCase()

	/**
	 * @return void
	 */
	public function testARecordWithoutAFileOrWithoutAnOwnerStillReadsHonestly(): void {
		$this->objects->joins = [
			['informatieobject' => 'rec-nofile', 'case' => self::CASE_B],
			['informatieobject' => 'rec-orphan', 'case' => self::CASE_B],
			['informatieobject' => '', 'case' => self::CASE_B],
		];
		$this->projection->method('folderOf')->willReturn(null);
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => match ($recordId) {
				'rec-nofile' => ['id' => 'rec-nofile', 'fileName' => 'seed.pdf'],
				'rec-orphan' => ['id' => 'rec-orphan', 'fileName' => 'x.pdf', 'fileId' => 300],
				default => null,
			}
		);
		$this->store->method('joinsFor')->willReturn([['case' => self::CASE_B, 'registrationDate' => '2026-01-01T00:00:00Z']]);

		$items = $this->reader->linkedDocuments(caseId: self::CASE_B);

		$this->assertCount(expectedCount: 1, haystack: $items, message: 'no file, no row; a blank join, no row');
		$this->assertSame(expected: 'rec-orphan', actual: $items[0]['id']);
		$this->assertSame(expected: '', actual: $items[0]['note'], message: 'joined to this case only: nobody else to name');
	}//end testARecordWithoutAFileOrWithoutAnOwnerStillReadsHonestly()

	/**
	 * @return void
	 */
	public function testNothingIsReadWithoutOpenRegister(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$reader = new LinkedDocumentsReader(
			settingsService: $settings,
			store: $this->store,
			projection: $this->projection,
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
		);

		$this->assertSame(expected: [], actual: $reader->linkedDocuments(caseId: self::CASE_B));
	}//end testNothingIsReadWithoutOpenRegister()
}//end class
