<?php

/**
 * The rules an upload and a metadata edit are checked against before anything is stored.
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

use DomainException;
use OCA\Dossiq\Service\DocumentUpload;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * DocumentUpload::requireType(), InformatieobjectStatusLifecycle::requireEditable() and
 * InformatieobjectMetadataNormaliser::editableFields().
 *
 * @covers \OCA\Dossiq\Service\DocumentUpload
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DossierEditAndUploadRulesTest extends TestCase {

	/**
	 * An upload names its case, its file name and its type, or it is refused by the first one missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnUploadNamesItsCaseFileAndType(): void {
		$this->assertSame(
			expected: 'type-1',
			actual: $this->upload(caseId: 'case-1', fileName: 'brief.pdf', type: 'type-1')->requireType()
		);

		$incomplete = [
			['  ', 'brief.pdf', 'type-1', 'caseId is required'],
			['case-1', ' ', 'type-1', 'bestandsnaam is required'],
			['case-1', 'brief.pdf', '', 'informatieobjecttype is required'],
		];
		foreach ($incomplete as [$caseId, $fileName, $type, $sentence]) {
			try {
				$this->upload(caseId: $caseId, fileName: $fileName, type: $type)->requireType();
				$this->fail(message: 'An incomplete upload was accepted: ' . $sentence);
			} catch (RuntimeException $refused) {
				$this->assertSame(expected: $sentence, actual: $refused->getMessage());
			}
		}
	}//end testAnUploadNamesItsCaseFileAndType()

	/**
	 * A missing document and a definitief one are refused; any other is answered as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testOnlyAStoredDocumentThatIsNotDefinitiefIsEditable(): void {
		$lifecycle = new InformatieobjectStatusLifecycle(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(
			expected: ['id' => 'doc-1', 'status' => 'in_bewerking'],
			actual: $lifecycle->requireEditable(current: ['id' => 'doc-1', 'status' => 'in_bewerking'], infoObjectId: 'doc-1')
		);

		try {
			$lifecycle->requireEditable(current: null, infoObjectId: 'gone');
			$this->fail(message: 'A missing document was editable.');
		} catch (RuntimeException $missing) {
			$this->assertSame(expected: 'Informatieobject not found: gone', actual: $missing->getMessage());
		}

		$this->expectException(exception: DomainException::class);
		$lifecycle->requireEditable(current: ['id' => 'doc-2', 'status' => 'final'], infoObjectId: 'doc-2');
	}//end testOnlyAStoredDocumentThatIsNotDefinitiefIsEditable()

	/**
	 * Only the editable fields present are kept, with the direction and the keywords normalised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testOnlyTheEditableFieldsAreKeptNormalised(): void {
		$normaliser = new InformatieobjectMetadataNormaliser();
		$fields = $normaliser->editableFields(
			metadata: ['title' => 'Brief', 'status' => 'final', 'direction' => 'incoming', 'keywords' => 'a, b']
		);

		$this->assertSame(
			expected: [
				'title' => 'Brief',
				'direction' => $normaliser->direction(value: 'incoming'),
				'keywords' => $normaliser->keywords(value: 'a, b'),
			],
			actual: $fields
		);
	}//end testOnlyTheEditableFieldsAreKeptNormalised()

	/**
	 * An upload of a file to a case.
	 *
	 * @param string $caseId   The case.
	 * @param string $fileName The file name.
	 * @param string $type     The document type.
	 *
	 * @return DocumentUpload The upload.
	 */
	private function upload(string $caseId, string $fileName, string $type): DocumentUpload {
		return new DocumentUpload(caseId: $caseId, fileName: $fileName, content: 'pdf', metadata: ['informatieobjecttype' => $type]);
	}//end upload()
}//end class
