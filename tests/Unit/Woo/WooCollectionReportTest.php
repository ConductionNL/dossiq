<?php

/**
 * Woo Collection Report Test
 *
 * The collection of a Woo request reported per custodian and per system, with
 * the twelve-candidate reconciliation of REQ-WRC-003, over the real case
 * documents, the real record store, the real assessment service and the real
 * plan service on one in-memory register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooCorpusRefused;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Woo\WooCollection
 * @uses   \OCA\Dossiq\Woo\WooCorpusRefused
 * @uses   \OCA\Dossiq\Woo\WooSearchPlans
 * @uses   \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses   \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class WooCollectionReportTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	private InMemoryRegister $register;

	private WooCollection $collection;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'woo_exclusion_schema' => 'wooExclusion',
				'woo_search_plan_schema' => 'wooSearchPlan',
				'woo_request_configuration_schema' => 'wooRequestConfiguration',
			][$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);

		$this->collection = new WooCollection(
			settingsService: $settings,
			caseDocuments: $caseDocuments,
			store: new DocumentRecordStore(settingsService: $settings),
			assessments: new WOODocumentAssessmentService(settingsService: $settings, userSession: $this->createMock(IUserSession::class), logger: $logger, caseDocuments: $caseDocuments),
			plans: new WooSearchPlans(settingsService: $settings),
		);

		$this->register->seed('wooSearchPlan', 'plan-1', [
			'case' => self::CASE_ID,
			'custodians' => [['name' => 'A'], ['name' => 'B']],
			'systems' => ['files', 'microsoft365'],
			'recordedAt' => '2026-10-01T10:00:00Z',
		]);
	}//end setUp()

	/**
	 * Put a gathered document on the case.
	 *
	 * @param string $id        The document uuid.
	 * @param string $custodian The plan custodian.
	 * @param int    $bytes     Its size.
	 *
	 * @return void
	 */
	private function document(string $id, string $custodian, int $bytes = 100): void {
		$this->register->seed('informatieobject', $id, [
			'bestandsomvang' => $bytes,
			'provenance' => ['source' => 'files', 'sourceSystem' => 'files', 'custodian' => $custodian],
		]);
		$this->register->seed('zaakinformatieobject', 'join-' . $id, ['case' => self::CASE_ID, 'informatieobject' => $id]);
	}//end document()

	/**
	 * A row by key.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string                           $key  The name key.
	 *
	 * @return array<string, array<string, mixed>> The rows by name.
	 */
	private function by(array $rows, string $key): array {
		return array_column($rows, null, $key);
	}//end by()

	/**
	 * REQ-WRC-002 "Volumes per custodian".
	 *
	 * @return void
	 */
	public function testVolumesAreSummedPerCustodian(): void {
		foreach (range(1, 5) as $i) {
			$this->document(id: 'doc-' . $i, custodian: 'A', bytes: (100 * $i));
		}

		$report = $this->collection->report(caseId: self::CASE_ID);
		$custodians = $this->by(rows: $report['custodians'], key: 'custodian');

		self::assertSame(5, $custodians['A']['documents']);
		self::assertSame(1500, $custodians['A']['bytes']);
	}//end testVolumesAreSummedPerCustodian()

	public function testAPlannedCustodianWithNothingShowsZero(): void {
		$this->document(id: 'doc-1', custodian: 'A');

		$report = $this->collection->report(caseId: self::CASE_ID);

		self::assertSame(['custodian' => 'B', 'documents' => 0, 'bytes' => 0, 'excluded' => 0], $this->by(rows: $report['custodians'], key: 'custodian')['B']);
		self::assertSame(0, $this->by(rows: $report['systems'], key: 'system')['microsoft365']['documents'], 'a planned system with nothing found shows zero');
		self::assertSame(1, $this->by(rows: $report['systems'], key: 'system')['files']['documents']);
	}//end testAPlannedCustodianWithNothingShowsZero()

	/**
	 * REQ-WRC-003 "What arrived reconciles with what was reviewed": 12 candidates.
	 *
	 * @return void
	 */
	public function testArrivedReconcilesWithReviewedAndExcluded(): void {
		foreach (range(1, 9) as $i) {
			$this->document(id: 'doc-' . $i, custodian: 'A');
		}

		$this->collection->record(caseId: self::CASE_ID, fields: ['source' => 'files', 'fileName' => 'copy-1.pdf', 'reason' => 'duplicate', 'duplicateOf' => 'doc-1', 'custodian' => 'A']);
		$this->collection->record(caseId: self::CASE_ID, fields: ['source' => 'files', 'fileName' => 'copy-2.pdf', 'reason' => 'duplicate', 'duplicateOf' => 'doc-2', 'custodian' => 'A']);
		$this->collection->record(caseId: self::CASE_ID, fields: ['source' => 'microsoft365', 'fileName' => 'kapot.msg', 'reason' => 'unreadable', 'custodian' => 'B']);
		foreach (range(1, 6) as $i) {
			$this->register->seed('wooDocumentAssessment', 'a-' . $i, ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-' . $i]);
		}

		$this->collection->exclude(caseId: self::CASE_ID, documentRef: 'doc-9', reason: 'out-of-period', note: 'Van 2019', userId: 'pjansen');

		$report = $this->collection->report(caseId: self::CASE_ID);
		self::assertSame(12, $report['arrived']);
		self::assertSame(4, $report['excluded']);
		self::assertSame(6, $report['assessed']);
		self::assertSame(2, $report['outstanding']);
		self::assertSame($report['arrived'], ($report['assessed'] + $report['excluded'] + $report['outstanding']));
		self::assertCount(4, $report['exclusions']);

		$real = new RealSchemaValidator();
		foreach ($this->register->all('wooExclusion') as $exclusion) {
			self::assertSame([], $real->errors(slug: 'wooExclusion', payload: $exclusion));
		}
	}//end testArrivedReconcilesWithReviewedAndExcluded()

	public function testExcludingRefusesAnAssessedAnUnknownOrARepeatedDocument(): void {
		$this->document(id: 'doc-1', custodian: 'A');
		$this->document(id: 'doc-2', custodian: 'A');
		$this->register->seed('wooDocumentAssessment', 'a-1', ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-1']);

		$refusals = [];
		foreach ([['doc-1', 'out-of-scope'], ['doc-x', 'out-of-scope'], ['doc-2', 'because']] as [$doc, $reason]) {
			try {
				$this->collection->exclude(caseId: self::CASE_ID, documentRef: $doc, reason: $reason, note: '', userId: 'pjansen');
			} catch (WooCorpusRefused $refused) {
				$refusals[] = [$refused->getMessage(), $refused->getStatus()];
			}
		}

		$this->collection->exclude(caseId: self::CASE_ID, documentRef: 'doc-2', reason: 'out-of-scope', note: '', userId: 'pjansen');
		try {
			$this->collection->exclude(caseId: self::CASE_ID, documentRef: 'doc-2', reason: 'out-of-scope', note: '', userId: 'pjansen');
		} catch (WooCorpusRefused $refused) {
			$refusals[] = [$refused->getMessage(), $refused->getStatus()];
		}

		self::assertSame([['document_assessed', 409], ['document_not_on_case', 404], ['unknown_reason', 400], ['document_excluded', 409]], $refusals);
		self::assertSame('A', $this->register->all('wooExclusion')[0]['custodian']);
	}//end testExcludingRefusesAnAssessedAnUnknownOrARepeatedDocument()
}//end class
