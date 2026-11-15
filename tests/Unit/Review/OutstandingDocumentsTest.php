<?php

/**
 * The documents of a case that still need a verdict: on the case, not set aside, not assessed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Review
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\OutstandingDocuments;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Review\OutstandingDocuments
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class OutstandingDocumentsTest extends TestCase {

	/**
	 * Settings over a register, with the document, assessment and exclusion schemas.
	 *
	 * @param object|null $register The register, or null for no OpenRegister.
	 * @param string      $name     The configured register name.
	 *
	 * @return SettingsService&MockObject
	 */
	private function settings(?object $register, string $name = 'dossiq'): SettingsService&MockObject {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => $name,
				'document_schema' => 'document',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'woo_exclusion_schema' => 'wooExclusion',
			][$key] ?? $default
		);

		return $settings;
	}//end settings()

	/**
	 * A document is outstanding when it is on the case, not set aside and not assessed.
	 *
	 * @return void
	 */
	public function testOnlyUnassessedDocumentsThatWereNotSetAsideAreOutstanding(): void {
		$register = new InMemoryRegister();
		foreach (['doc-1', 'doc-2', 'doc-3', 'doc-4'] as $doc) {
			$register->seed(schema: 'document', uuid: $doc, row: ['case' => 'case-1']);
		}

		$register->seed(schema: 'document', uuid: 'doc-other', row: ['case' => 'case-2']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$register->seed(schema: 'wooExclusion', uuid: 'x-1', row: ['case' => 'case-1', 'documentRef' => 'doc-2']);

		$outstanding = (new OutstandingDocuments(settingsService: $this->settings(register: $register)))->forCase(caseId: 'case-1');

		self::assertSame(['count' => 2, 'documents' => ['doc-3', 'doc-4']], $outstanding);
	}//end testOnlyUnassessedDocumentsThatWereNotSetAsideAreOutstanding()

	/**
	 * REQ-CRA-011: an exclusion the caller cannot read still takes its document off the outstanding list.
	 *
	 * @return void
	 */
	public function testADocumentSetAsideOutOfTheCallersSightIsNotOutstanding(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'document', uuid: 'doc-1', row: ['case' => 'case-1']);
		$register->seed(schema: 'document', uuid: 'doc-2', row: ['case' => 'case-1']);
		$register->seed(schema: 'wooExclusion', uuid: 'x-1', row: ['case' => 'case-1', 'documentRef' => 'doc-2']);
		$register->hiddenFromCaller = ['wooExclusion'];

		$outstanding = (new OutstandingDocuments(settingsService: $this->settings(register: $register)))->forCase(caseId: 'case-1');

		self::assertSame(['count' => 1, 'documents' => ['doc-1']], $outstanding);
	}//end testADocumentSetAsideOutOfTheCallersSightIsNotOutstanding()

	/**
	 * Without OpenRegister, a register or any document, nothing is outstanding.
	 *
	 * @return void
	 */
	public function testNothingIsOutstandingWithoutDocuments(): void {
		$none = ['count' => 0, 'documents' => []];

		self::assertSame($none, (new OutstandingDocuments(settingsService: $this->settings(register: null)))->forCase(caseId: 'case-1'));
		self::assertSame($none, (new OutstandingDocuments(settingsService: $this->settings(register: new InMemoryRegister(), name: '')))->forCase(caseId: 'case-1'));
		self::assertSame($none, (new OutstandingDocuments(settingsService: $this->settings(register: new InMemoryRegister())))->forCase(caseId: 'case-1'));
	}//end testNothingIsOutstandingWithoutDocuments()

	/**
	 * Under review is every document on the case that was not set aside, assessed or not.
	 *
	 * @return void
	 */
	public function testUnderReviewIsEveryDocumentThatWasNotSetAside(): void {
		$register = new InMemoryRegister();
		foreach (['doc-1', 'doc-2', 'doc-3'] as $doc) {
			$register->seed(schema: 'document', uuid: $doc, row: ['case' => 'case-1']);
		}

		$register->seed(schema: 'document', uuid: 'doc-other', row: ['case' => 'case-2']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$register->seed(schema: 'wooExclusion', uuid: 'x-1', row: ['case' => 'case-1', 'documentRef' => 'doc-2']);

		$documents = new OutstandingDocuments(settingsService: $this->settings(register: $register));

		self::assertSame(['doc-1', 'doc-3'], $documents->underReview(caseId: 'case-1'));
		self::assertSame([], $documents->underReview(caseId: 'case-none'));
		self::assertSame([], (new OutstandingDocuments(settingsService: $this->settings(register: null)))->underReview(caseId: 'case-1'));
		self::assertSame([], (new OutstandingDocuments(settingsService: $this->settings(register: $register, name: '')))->underReview(caseId: 'case-1'));
	}//end testUnderReviewIsEveryDocumentThatWasNotSetAside()

	/**
	 * The redacted copy of a partly public document is part of that document's assessment.
	 *
	 * The case folder's listener records the copy as a document of the case, so
	 * without this it would wait for an assessment of its own.
	 *
	 * @return void
	 */
	public function testARedactedCopyIsNeitherUnderReviewNorOutstanding(): void {
		$register = new InMemoryRegister();
		foreach (['doc-1', 'doc-1-gelakt', 'doc-2'] as $doc) {
			$register->seed(schema: 'document', uuid: $doc, row: ['case' => 'case-1']);
		}

		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: [
			'caseRef' => 'case-1',
			'documentRef' => 'doc-1',
			'classification' => 'deels_openbaar',
			'redactedDocumentRef' => 'doc-1-gelakt',
		]);

		$documents = new OutstandingDocuments(settingsService: $this->settings(register: $register));

		self::assertSame(['doc-1', 'doc-2'], $documents->underReview(caseId: 'case-1'));
		self::assertSame(['count' => 1, 'documents' => ['doc-2']], $documents->forCase(caseId: 'case-1'));
	}//end testARedactedCopyIsNeitherUnderReviewNorOutstanding()
}//end class
