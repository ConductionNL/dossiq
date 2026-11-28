<?php

/**
 * Unit tests for UploadedDocumentWriter: an uploaded file and its record written onto the case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\DossierRecords;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCA\Dossiq\Service\Zaakdossier\UploadedDocumentWriter;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An upload written onto its case, through UploadedDocumentWriter.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class UploadedDocumentWriterTest extends TestCase {

	private const CONFIG = [
		'register' => 'dossiq',
		'dossier_informatieobject_schema' => 'io',
		'dossier_informatieobjecttype_schema' => 'iot',
	];

	private const SENDER = ['sender' => ['name' => 'Sanne de Vries'], 'recipients' => [['name' => 'Gemeente']]];

	/**
	 * The register the record is stored in.
	 *
	 * @var LifecycleRegister
	 */
	private LifecycleRegister $register;

	/**
	 * A fresh register with one document type per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new LifecycleRegister();
		$this->register->seed(schema: 'iot', uuid: 'type-1', row: ['vertrouwelijkheidaanduiding' => 'vertrouwelijk']);
	}//end setUp()

	/**
	 * A writer over the register, with the file store the test gives.
	 *
	 * @param DocumentRecordStore $files Where the file lands on the case, and the record it already has.
	 *
	 * @return UploadedDocumentWriter The writer.
	 */
	private function writer(DocumentRecordStore $files): UploadedDocumentWriter {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$correspondents = $this->createMock(originalClassName: CorrespondentWriter::class);
		$correspondents->method('resolveFor')->willReturn(self::SENDER);

		return new UploadedDocumentWriter(
			normaliser: new InformatieobjectMetadataNormaliser(),
			correspondents: $correspondents,
			recordStore: $files,
			records: new DossierRecords(
				settingsService: $settings,
				accessGuard: $this->createMock(originalClassName: InformatieobjectAccessGuard::class)
			)
		);
	}//end writer()

	/**
	 * A new upload gets a draft record of its file, typed, classified by its type and hashed.
	 *
	 * @return void
	 */
	public function testANewUploadGetsADraftRecord(): void {
		$files = $this->createMock(originalClassName: DocumentRecordStore::class);
		$files->expects($this->once())->method('storeFileOnObject')->with('case-1', 'brief.pdf', 'bytes')->willReturn(0);
		$files->expects($this->never())->method('findRecord');

		$written = $this->writer(files: $files)->write(
			caseId: 'case-1',
			fileName: 'brief.pdf',
			content: 'bytes',
			metadata: ['informatieobjecttype' => 'type-1']
		);

		$record = $this->register->row('io', $written['id']);
		$this->assertSame(expected: 'brief.pdf', actual: $record['title']);
		$this->assertSame(expected: 'draft', actual: $record['status']);
		$this->assertSame(expected: 'vertrouwelijk', actual: $record['vertrouwelijkheidaanduiding']);
		$this->assertSame(expected: hash(algo: 'sha256', data: 'bytes'), actual: $record['integrity']['value']);
		$this->assertSame(expected: 5, actual: $record['bestandsomvang']);
		$this->assertSame(expected: self::SENDER, actual: $written['correspondents']);
		$this->assertSame(expected: $written['id'], actual: $written['summary']['id']);
		$this->assertSame(expected: 'Sanne de Vries', actual: $written['summary']['sender']['name']);
	}//end testANewUploadGetsADraftRecord()

	/**
	 * A file the listener already recorded keeps its record, which gets the upload's metadata.
	 *
	 * @return void
	 */
	public function testARecordedFileKeepsItsRecord(): void {
		$this->register->seed(schema: 'io', uuid: 'doc-7', row: ['fileId' => 42, 'projectedBy' => 'listener']);
		$files = $this->createMock(originalClassName: DocumentRecordStore::class);
		$files->method('storeFileOnObject')->willReturn(42);
		$files->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => self::recordOfFile(fileId: $fileId)
		);

		$written = $this->writer(files: $files)->write(
			caseId: 'case-1',
			fileName: 'brief.pdf',
			content: 'bytes',
			metadata: ['informatieobjecttype' => 'type-1', 'title' => 'Aanvraag']
		);

		$this->assertSame(expected: 'doc-7', actual: $written['id']);
		$this->assertCount(expectedCount: 1, haystack: $this->register->all('io'));
		$record = $this->register->row('io', 'doc-7');
		$this->assertSame(expected: 'Aanvraag', actual: $record['title']);
		$this->assertSame(expected: 'listener', actual: $record['projectedBy']);
		$this->assertSame(expected: 42, actual: $record['fileId']);
	}//end testARecordedFileKeepsItsRecord()

	/**
	 * The record the listener made for file 42, and none for any other file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private static function recordOfFile(int $fileId): ?array {
		if ($fileId !== 42) {
			return null;
		}

		return ['id' => 'doc-7', 'fileId' => 42, 'projectedBy' => 'listener'];
	}//end recordOfFile()

	/**
	 * An upload without a type stores nothing.
	 *
	 * @return void
	 */
	public function testAnUploadWithoutATypeStoresNothing(): void {
		$files = $this->createMock(originalClassName: DocumentRecordStore::class);
		$files->expects($this->never())->method('storeFileOnObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'informatieobjecttype is required');
		$this->writer(files: $files)->write(caseId: 'case-1', fileName: 'brief.pdf', content: 'bytes', metadata: []);
	}//end testAnUploadWithoutATypeStoresNothing()
}//end class
