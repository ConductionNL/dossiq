<?php

/**
 * DocumentDefaults record updates unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * What a write and a rename change on a stored record, and a row's uuid.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentDefaults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DocumentDefaultsRecordUpdatesTest extends TestCase {

	/**
	 * The defaults over mocked collaborators.
	 *
	 * @return DocumentDefaults
	 */
	private function defaults(): DocumentDefaults {
		return new DocumentDefaults(
			store: $this->createMock(originalClassName: DocumentRecordStore::class),
			caseTypes: $this->createMock(originalClassName: CaseTypeResolver::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
		);
	}//end defaults()

	/**
	 * Size, format, integrity and file id follow the file; the rest of the record stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testASecondWriteRefreshesWhatFollowsTheFile(): void {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getSize')->willReturn(300000000);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getId')->willReturn(42);

		$record = $this->defaults()->refreshed(record: ['id' => 'rec-1', 'title' => 'Besluit', 'fileId' => 7], node: $file);

		$this->assertSame(
			expected: [
				'id' => 'rec-1',
				'title' => 'Besluit',
				'fileId' => 42,
				'bestandsomvang' => 300000000,
				'format' => 'application/pdf',
				'integrity' => [],
			],
			actual: $record
		);
	}//end testASecondWriteRefreshesWhatFollowsTheFile()

	/**
	 * A derived title follows the new name; a title set by hand stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testARenameMovesADerivedTitleAndKeepsAHandSetOne(): void {
		$names = ['previous' => 'brief.pdf', 'current' => 'besluit.pdf'];

		$derived = $this->defaults()->renamed(record: ['title' => 'brief', 'fileName' => 'brief.pdf'], names: $names);
		$this->assertSame(expected: ['title' => 'besluit', 'fileName' => 'besluit.pdf'], actual: $derived);

		$handSet = $this->defaults()->renamed(record: ['title' => 'Mijn brief', 'fileName' => 'brief.pdf'], names: $names);
		$this->assertSame(expected: ['title' => 'Mijn brief', 'fileName' => 'besluit.pdf'], actual: $handSet);
	}//end testARenameMovesADerivedTitleAndKeepsAHandSetOne()

	/**
	 * A row's uuid is read from id, then uuid, then @self.id, trimmed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testARowsUuidIsReadFromItsIdFields(): void {
		$defaults = $this->defaults();

		$this->assertSame(expected: 'a', actual: $defaults->idOf(row: ['id' => ' a ', 'uuid' => 'b']));
		$this->assertSame(expected: 'b', actual: $defaults->idOf(row: ['uuid' => 'b', '@self' => ['id' => 'c']]));
		$this->assertSame(expected: 'c', actual: $defaults->idOf(row: ['@self' => ['id' => 'c']]));
		$this->assertSame(expected: '', actual: $defaults->idOf(row: []));
	}//end testARowsUuidIsReadFromItsIdFields()
}//end class
