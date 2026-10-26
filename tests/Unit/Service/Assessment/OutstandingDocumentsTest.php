<?php

/**
 * The outstanding documents of a case carry the title a handler knows them by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
use OCA\Dossiq\Review\OutstandingDocuments as ReviewOutstandingDocuments;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-DAS-001 over the real assessment service, record store and an in-memory register.
 *
 * @covers \OCA\Dossiq\Service\Assessment\OutstandingDocuments
 *
 * @uses \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses \OCA\Dossiq\Review\OutstandingDocuments
 */
class OutstandingDocumentsTest extends TestCase {

	/**
	 * The register every collaborator reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed case-1 with three case-file documents and one filed before the case file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'informatieobject', uuid: 'io-1', row: ['title' => 'Notitie budget verlichting', 'fileName' => 'notitie.pdf']);
		$this->store->seed(schema: 'informatieobject', uuid: 'io-2', row: ['title' => '', 'fileName' => 'export-meldingen.xlsx']);
		$this->store->seed(schema: 'informatieobject', uuid: 'io-3', row: ['title' => 'Mail aannemer']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-1', row: ['case' => 'case-1', 'informatieobject' => 'io-1']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-2', row: ['case' => 'case-1', 'informatieobject' => 'io-2']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-3', row: ['case' => 'case-1', 'informatieobject' => 'io-3']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-9', row: ['case' => 'case-2', 'informatieobject' => 'io-9']);
		$this->store->seed(schema: 'document', uuid: 'doc-1', row: ['case' => 'case-1', 'title' => 'Brief van 3 maart']);
		$this->store->seed(schema: 'assessment', uuid: 'as-1', row: ['caseRef' => 'case-1', 'documentRef' => 'io-3', 'classification' => 'openbaar']);
	}//end setUp()

	/**
	 * The service under test, over the seeded register.
	 *
	 * @return OutstandingDocuments
	 */
	private function service(?CaseDocumentDrafts $drafts = null): OutstandingDocuments {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'document_schema' => 'document',
				'woo_assessment_schema' => 'assessment',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default)
		);

		$caseDocuments = new WooCaseDocuments(
			settingsService: $settings,
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			logger: new NullLogger(),
		);

		return new OutstandingDocuments(
			assessments: new WOODocumentAssessmentService(
				settingsService: $settings,
				userSession: $this->createMock(IUserSession::class),
				logger: new NullLogger(),
				caseDocuments: $caseDocuments,
				outstanding: new ReviewOutstandingDocuments(settingsService: $settings, caseDocuments: $caseDocuments, drafts: $drafts),
			),
			records: new DocumentRecordStore(settingsService: $settings),
			settings: $settings,
		);
	}//end service()

	/**
	 * Only the documents without an assessment are named, each with its title.
	 *
	 * @return void
	 */
	public function testItNamesTheDocumentsWithoutAnAssessment(): void {
		$answer = $this->service()->forCase(caseId: 'case-1');

		$this->assertSame(3, $answer['count']);
		$titles = array_column($answer['documents'], 'title', 'id');
		$this->assertSame('Notitie budget verlichting', $titles['io-1']);
		$this->assertArrayNotHasKey('io-3', $titles, 'An assessed document is not outstanding');
		$this->assertArrayNotHasKey('io-9', $titles, 'Another case its document is not listed');
	}//end testItNamesTheDocumentsWithoutAnAssessment()

	/**
	 * A letter drafted from the case type's template is not a document under review.
	 *
	 * @return void
	 */
	public function testADraftedLetterIsNotADocumentToAssess(): void {
		$this->store->seed(schema: 'informatieobject', uuid: 'io-letter', row: ['title' => 'Besluit Woo-verzoek', 'informatieobjecttype' => 'iot-besluit']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-letter', row: ['case' => 'case-1', 'informatieobject' => 'io-letter']);
		$drafts = $this->createMock(originalClassName: CaseDocumentDrafts::class);
		$drafts->method('documentTypesForCase')->willReturn(['Besluit', 'iot-besluit']);

		$answer = $this->service(drafts: $drafts)->forCase(caseId: 'case-1');

		$this->assertSame(expected: 3, actual: $answer['count']);
		$this->assertArrayNotHasKey(key: 'io-letter', array: array_column($answer['documents'], 'title', 'id'));
	}//end testADraftedLetterIsNotADocumentToAssess()

	/**
	 * Without a title the file name stands in, and an earlier document is read from its own schema.
	 *
	 * @return void
	 */
	public function testAFileNameStandsInAndAnEarlierDocumentIsNamed(): void {
		$titles = array_column($this->service()->forCase(caseId: 'case-1')['documents'], 'title', 'id');

		$this->assertSame('export-meldingen.xlsx', $titles['io-2']);
		$this->assertSame('Brief van 3 maart', $titles['doc-1']);
	}//end testAFileNameStandsInAndAnEarlierDocumentIsNamed()

	/**
	 * A document whose record is gone stays outstanding, with an empty title.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentStaysInTheList(): void {
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-4', row: ['case' => 'case-1', 'informatieobject' => 'io-gone']);

		$answer = $this->service()->forCase(caseId: 'case-1');

		$this->assertSame(4, $answer['count']);
		$this->assertSame('', array_column($answer['documents'], 'title', 'id')['io-gone']);
	}//end testAnUnreadableDocumentStaysInTheList()

	/**
	 * A case with nothing outstanding answers an empty list, not a missing key.
	 *
	 * @return void
	 */
	public function testNothingOutstandingAnswersAnEmptyList(): void {
		$this->assertSame(['count' => 0, 'documents' => []], $this->service()->forCase(caseId: 'case-none'));
	}//end testNothingOutstandingAnswersAnEmptyList()
}//end class
