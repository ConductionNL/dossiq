<?php

/**
 * AssessedDocuments unit tests
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
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Service\Assessment\AssessedDocuments;
use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
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
 * REQ-DAS-004 over the real assessment service, record store and a register
 * that hides what the caller may not read.
 *
 * @covers \OCA\Dossiq\Service\Assessment\AssessedDocuments
 *
 * @uses \OCA\Dossiq\Service\Assessment\OutstandingDocuments
 * @uses \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses \OCA\Dossiq\Review\OutstandingDocuments
 */
class AssessedDocumentsTest extends TestCase {

	/**
	 * The register every collaborator reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed case-1 with four documents: one outstanding, two assessed, one set aside.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// A scoped read (the caller's) does not see a row marked `hidden`; an
		// unscoped one does. That is the difference REQ-WSA-004 rests on.
		$this->store = new class () extends InMemoryRegister {

			/**
			 * Search as ObjectService does, hiding `hidden` rows from a scoped read.
			 *
			 * @param string               $register      Ignored.
			 * @param string               $schema        The schema slug.
			 * @param array<string, mixed> $filters       Equality filters.
			 * @param bool                 $_rbac         False reads past the caller's access.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$rows = parent::searchObjectsBySlug($register, $schema, $filters, $_rbac, $_multitenancy);
				if ($_rbac === false) {
					return $rows;
				}

				return array_values(array_filter($rows, static fn (array $row): bool => ($row['hidden'] ?? false) !== true));
			}//end searchObjectsBySlug()
		};

		foreach (['io-1' => 'Notitie budget verlichting', 'io-2' => 'Mail aannemer', 'io-3' => 'Memo wethouder', 'io-4' => 'Dubbele bijlage'] as $id => $title) {
			$this->store->seed(schema: 'informatieobject', uuid: $id, row: ['title' => $title]);
			$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-' . $id, row: ['case' => 'case-1', 'informatieobject' => $id]);
		}

		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'j-9', row: ['case' => 'case-2', 'informatieobject' => 'io-9']);
		$this->store->seed(
			schema: 'assessment',
			uuid: 'as-2',
			row: ['caseRef' => 'case-1', 'documentRef' => 'io-2', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e'], '@self' => ['owner' => 'jan']]
		);
		$this->store->seed(
			schema: 'assessment',
			uuid: 'as-3',
			row: ['caseRef' => 'case-1', 'documentRef' => 'io-3', 'classification' => 'openbaar', 'hidden' => true]
		);
		$this->store->seed(schema: 'assessment', uuid: 'as-9', row: ['caseRef' => 'case-2', 'documentRef' => 'io-9', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'exclusion', uuid: 'ex-4', row: ['case' => 'case-1', 'documentRef' => 'io-4']);
	}//end setUp()

	/**
	 * The service under test, over the seeded register.
	 *
	 * @return AssessedDocuments
	 */
	private function service(): AssessedDocuments {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'document_schema' => 'document',
				'woo_assessment_schema' => 'assessment',
				'woo_exclusion_schema' => 'exclusion',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default)
		);

		$assessments = new WOODocumentAssessmentService(
			settingsService: $settings,
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger(),
			caseDocuments: new WooCaseDocuments(
				settingsService: $settings,
				rootFolder: $this->createMock(IRootFolder::class),
				logger: new NullLogger(),
			),
		);

		return new AssessedDocuments(
			assessments: $assessments,
			outstanding: new OutstandingDocuments(
				assessments: $assessments,
				records: new DocumentRecordStore(settingsService: $settings),
				settings: $settings,
			),
			settings: $settings,
		);
	}//end service()

	/**
	 * Every document under review is listed with its title; a set-aside one and another case's are not.
	 *
	 * @return void
	 */
	public function testEveryDocumentUnderReviewIsListed(): void {
		$answer = $this->service()->forCase(caseId: 'case-1');

		$this->assertSame(3, $answer['count']);
		$this->assertSame(1, $answer['outstanding']);
		$this->assertSame(
			['io-1' => 'Notitie budget verlichting', 'io-2' => 'Mail aannemer', 'io-3' => 'Memo wethouder'],
			array_column($answer['documents'], 'title', 'id')
		);
	}//end testEveryDocumentUnderReviewIsListed()

	/**
	 * An outstanding document says so and carries no assessment.
	 *
	 * @return void
	 */
	public function testAnOutstandingDocumentCarriesNoAssessment(): void {
		$rows = array_column($this->service()->forCase(caseId: 'case-1')['documents'], null, 'id');

		$this->assertTrue($rows['io-1']['outstanding']);
		$this->assertNull($rows['io-1']['assessment']);
	}//end testAnOutstandingDocumentCarriesNoAssessment()

	/**
	 * An assessed document carries its assessment with its id, without the references and the metadata.
	 *
	 * @return void
	 */
	public function testAnAssessedDocumentCarriesItsAssessment(): void {
		$rows = array_column($this->service()->forCase(caseId: 'case-1')['documents'], null, 'id');

		$this->assertFalse($rows['io-2']['outstanding']);
		$this->assertSame(
			['classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e'], 'id' => 'as-2'],
			$rows['io-2']['assessment']
		);
	}//end testAnAssessedDocumentCarriesItsAssessment()

	/**
	 * An assessment the caller may not read is not answered, and its document is not outstanding.
	 *
	 * @return void
	 */
	public function testAnAssessmentTheCallerMayNotReadStaysWithItsReviewer(): void {
		$rows = array_column($this->service()->forCase(caseId: 'case-1')['documents'], null, 'id');

		$this->assertFalse($rows['io-3']['outstanding'], 'Assessed by somebody else is still assessed');
		$this->assertNull($rows['io-3']['assessment']);
	}//end testAnAssessmentTheCallerMayNotReadStaysWithItsReviewer()

	/**
	 * A case without documents answers an empty list, not a missing key.
	 *
	 * @return void
	 */
	public function testACaseWithoutDocumentsAnswersAnEmptyList(): void {
		$this->assertSame(['count' => 0, 'outstanding' => 0, 'documents' => []], $this->service()->forCase(caseId: 'case-none'));
	}//end testACaseWithoutDocumentsAnswersAnEmptyList()
}//end class
