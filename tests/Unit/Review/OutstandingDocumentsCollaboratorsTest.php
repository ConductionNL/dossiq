<?php

/**
 * The outstanding documents of a case when the case documents, the review
 * relevance and the case's own drafts come from their collaborators, and when
 * a schema is not configured.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\OutstandingDocuments;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Review\OutstandingDocuments
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OutstandingDocumentsCollaboratorsTest extends TestCase {

	/**
	 * Settings over a register; a schema missing from $schemas is not configured.
	 *
	 * @param object                $register The register.
	 * @param array<string, string> $schemas  Configured schema per key.
	 *
	 * @return SettingsService&MockObject
	 */
	private function settings(object $register, array $schemas): SettingsService&MockObject {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq'] + $schemas)[$key] ?? $default
		);

		return $settings;
	}//end settings()

	/**
	 * The case documents and their types, as the case upload stores them.
	 *
	 * @param array<int, string>        $ids   The document ids on the case.
	 * @param array<string, array|null> $metas The metadata per document id.
	 *
	 * @return WooCaseDocuments&MockObject
	 */
	private function caseDocuments(array $ids, array $metas = []): WooCaseDocuments&MockObject {
		$documents = $this->createMock(WooCaseDocuments::class);
		$documents->method('idsFor')->with('case-1')->willReturn($ids);
		$documents->method('meta')->willReturnCallback(static fn (string $id): ?array => $metas[$id] ?? null);

		return $documents;
	}//end caseDocuments()

	/**
	 * Documents of a type the case drafts itself are not under review; documents of other or no type are.
	 *
	 * @return void
	 */
	public function testDocumentsOfADraftedTypeAreLeftOut(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-4']);

		$drafts = $this->createMock(CaseDocumentDrafts::class);
		$drafts->expects(self::once())->method('documentTypesForCase')->with('case-1')->willReturn(['besluit']);

		$outstanding = new OutstandingDocuments(
			settingsService: $this->settings($register, ['woo_assessment_schema' => 'wooDocumentAssessment']),
			caseDocuments: $this->caseDocuments(
				['doc-1', 'doc-2', 'doc-3', 'doc-4'],
				['doc-1' => ['informatieobjecttype' => 'stuk'], 'doc-2' => ['informatieobjecttype' => 'besluit'], 'doc-4' => ['informatieobjecttype' => 'stuk']]
			),
			drafts: $drafts,
		);

		self::assertSame(['count' => 2, 'documents' => ['doc-1', 'doc-3']], $outstanding->forCase(caseId: 'case-1'));
	}//end testDocumentsOfADraftedTypeAreLeftOut()

	/**
	 * A case that drafts nothing keeps every document; no document type is read.
	 *
	 * @return void
	 */
	public function testACaseThatDraftsNothingKeepsEveryDocument(): void {
		$drafts = $this->createMock(CaseDocumentDrafts::class);
		$drafts->method('documentTypesForCase')->willReturn([]);
		$documents = $this->caseDocuments(['doc-1', 'doc-2']);
		$documents->expects(self::never())->method('meta');

		$outstanding = new OutstandingDocuments(
			settingsService: $this->settings(new InMemoryRegister(), []),
			caseDocuments: $documents,
			drafts: $drafts,
		);

		self::assertSame(['count' => 2, 'documents' => ['doc-1', 'doc-2']], $outstanding->forCase(caseId: 'case-1'));
	}//end testACaseThatDraftsNothingKeepsEveryDocument()

	/**
	 * With the review relevance available, it decides what is outstanding from the documents under review and the assessed ones.
	 *
	 * @return void
	 */
	public function testTheReviewRelevanceDecidesWhatIsOutstanding(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$register->seed(schema: 'wooExclusion', uuid: 'x-1', row: ['case' => 'case-1', 'documentRef' => 'doc-2']);

		$reviews = $this->createMock(DocumentRelevance::class);
		$reviews->expects(self::once())
			->method('outstanding')
			->with('case-1', ['doc-1', 'doc-3'], ['doc-1' => true])
			->willReturn(['doc-3']);

		$outstanding = new OutstandingDocuments(
			settingsService: $this->settings($register, ['woo_assessment_schema' => 'wooDocumentAssessment', 'woo_exclusion_schema' => 'wooExclusion']),
			caseDocuments: $this->caseDocuments(['doc-1', 'doc-2', 'doc-3']),
			reviews: $reviews,
		);

		self::assertSame(['count' => 1, 'documents' => ['doc-3']], $outstanding->forCase(caseId: 'case-1'));
	}//end testTheReviewRelevanceDecidesWhatIsOutstanding()

	/**
	 * Without a document schema nothing is on the case; without assessment or exclusion schemas every document is outstanding.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemas(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'document', uuid: 'doc-1', row: ['case' => 'case-1']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);

		self::assertSame(
			['count' => 0, 'documents' => []],
			(new OutstandingDocuments(settingsService: $this->settings($register, [])))->forCase(caseId: 'case-1')
		);
		self::assertSame(
			['count' => 1, 'documents' => ['doc-1']],
			(new OutstandingDocuments(settingsService: $this->settings($register, ['document_schema' => 'document'])))->forCase(caseId: 'case-1')
		);
	}//end testUnconfiguredSchemas()
}//end class
