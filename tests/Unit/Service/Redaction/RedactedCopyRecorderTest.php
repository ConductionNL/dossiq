<?php

/**
 * Unit tests for recording a redacted copy as a document of the case.
 *
 * A redacted copy that is stored but never recorded is a file on the case
 * that no list shows and no publication can pick up. These tests pin that a
 * copy is recorded once, joined to the case, inherits the original's
 * classification, and that every way the recording can fail raises instead of
 * answering an id nobody can resolve.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
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
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Service\Redaction\RedactedCopyRecorder;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Recording, joining and refusing redacted copies.
 *
 * @covers \OCA\Dossiq\Service\Redaction\RedactedCopyRecorder
 */
class RedactedCopyRecorderTest extends TestCase {

	private DocumentRecordStore&MockObject $store;

	private IRootFolder&MockObject $rootFolder;

	protected function setUp(): void {
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * A file already recorded is joined to the case under its record, and readers are inherited.
	 *
	 * @return void
	 */
	public function testAnAlreadyRecordedFileIsJoinedAndKeepsItsReaders(): void {
		$original = ['id' => 'doc-1', 'title' => 'Besluit'];
		$readers = $this->createMock(RestrictedDocumentReaders::class);
		$readers->expects($this->once())->method('inherit')->with($original, 42)->willReturn(true);
		$this->store->method('findRecord')->with('', 42)->willReturn(['id' => 'rec-9']);
		$this->store->expects($this->never())->method('saveRecord');
		$this->store->expects($this->once())->method('ensureJoin')->with('case-1', 'rec-9')->willReturn(true);

		$recorder = new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder, readers: $readers);

		$this->assertSame('rec-9', $recorder->recordFile('case-1', $original, 42));
	}//end testAnAlreadyRecordedFileIsJoinedAndKeepsItsReaders()

	/**
	 * A new copy is recorded from its file, inheriting the original's classification.
	 *
	 * @return void
	 */
	public function testANewCopyIsRecordedFromItsFileWithTheInheritedClassification(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('besluit_gelakt.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getSize')->willReturn(2048);
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->rootFolder->method('getById')->with(42)->willReturn([$file]);
		$this->store->method('findRecord')->willReturn(null);

		$saved = null;
		$this->store->expects($this->once())->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved = $record;
				return 'rec-new';
			}
		);
		$this->store->expects($this->once())->method('ensureJoin')->with('case-1', 'rec-new');

		$recorder = new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder);
		$original = ['title' => 'Besluit', 'informatieobjecttype' => 'iot-1', 'vertrouwelijkheidaanduiding' => 'openbaar', 'taal' => '', 'bronorganisatie' => '001234567'];

		$this->assertSame('rec-new', $recorder->recordFile('case-1', $original, 42));
		$this->assertSame(gmdate('Y-m-d'), $saved['creatiedatum']);
		unset($saved['creatiedatum']);
		$this->assertSame(
			[
				'title' => 'besluit_gelakt',
				'fileName' => 'besluit_gelakt.pdf',
				'fileId' => 42,
				'bestandsomvang' => 2048,
				'format' => 'application/pdf',
				'status' => 'draft',
				'description' => 'Redacted copy of Besluit',
				'informatieobjecttype' => 'iot-1',
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'bronorganisatie' => '001234567',
			],
			$saved
		);
	}//end testANewCopyIsRecordedFromItsFileWithTheInheritedClassification()

	/**
	 * A save that answers no id raises, and nothing is joined.
	 *
	 * @return void
	 */
	public function testACopyThatGetsNoRecordRaisesAndJoinsNothing(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.pdf');
		$this->rootFolder->method('getById')->willReturn([$file]);
		$this->store->method('findRecord')->willReturn(['id' => '']);
		$this->store->method('saveRecord')->willReturn('');
		$this->store->expects($this->never())->method('ensureJoin');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('copy_not_recorded: the redacted copy got no informatieobject');

		(new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder))->recordFile('case-1', [], 42);
	}//end testACopyThatGetsNoRecordRaisesAndJoinsNothing()

	/**
	 * A file that cannot be found raises with its id, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAMissingFileRaisesWithItsId(): void {
		$this->rootFolder->method('getById')->with(77)->willReturn([]);
		$this->store->method('findRecord')->willReturn(null);
		$this->store->expects($this->never())->method('saveRecord');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('copy_file_missing: no file 77');

		(new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder))->recordFile('case-1', [], 77);
	}//end testAMissingFileRaisesWithItsId()

	/**
	 * An upload is stored on the case under the original's name, marked redacted, then recorded.
	 *
	 * @return void
	 */
	public function testAnUploadIsStoredUnderTheOriginalsNameAndRecorded(): void {
		$this->store->expects($this->once())
			->method('storeFileOnObject')
			->with('case-1', $this->matchesRegularExpression('/^besluit_gelakt_\d{8}T\d{6}\.pdf$/'), '%PDF-1.7')
			->willReturn(55);
		$this->store->method('findRecord')->with('', 55)->willReturn(['id' => 'rec-55']);
		$this->store->expects($this->once())->method('ensureJoin')->with('case-1', 'rec-55');

		$recorder = new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder);

		$this->assertSame(
			['fileId' => 55, 'recordId' => 'rec-55'],
			$recorder->storeUpload('case-1', ['fileName' => 'besluit.docx'], 'mijn-versie.pdf', '%PDF-1.7')
		);
	}//end testAnUploadIsStoredUnderTheOriginalsNameAndRecorded()

	/**
	 * An upload without an extension keeps none, and a name falls back to the title.
	 *
	 * @return void
	 */
	public function testAnUploadWithoutAnExtensionKeepsNone(): void {
		$this->store->expects($this->once())
			->method('storeFileOnObject')
			->with('case-1', $this->matchesRegularExpression('/^Advies_gelakt_\d{8}T\d{6}$/'), 'tekst')
			->willReturn(56);
		$this->store->method('findRecord')->willReturn(['id' => 'rec-56']);

		$result = (new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder))
			->storeUpload('case-1', ['title' => 'Advies'], 'versie', 'tekst');

		$this->assertSame(56, $result['fileId']);
	}//end testAnUploadWithoutAnExtensionKeepsNone()

	/**
	 * An upload that could not be stored raises before anything is recorded.
	 *
	 * @return void
	 */
	public function testAnUploadThatWasNotStoredRaises(): void {
		$this->store->method('storeFileOnObject')->willReturn(0);
		$this->store->expects($this->never())->method('findRecord');
		$this->store->expects($this->never())->method('ensureJoin');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('upload_not_stored: the uploaded version could not be read back');

		(new RedactedCopyRecorder(store: $this->store, rootFolder: $this->rootFolder))
			->storeUpload('case-1', ['fileName' => 'besluit.pdf'], 'v.pdf', '%PDF');
	}//end testAnUploadThatWasNotStoredRaises()
}//end class
