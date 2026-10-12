<?php

/**
 * Tests for BatchSelection: the documents a review batch takes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
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

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\BatchSelection;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Review\BatchSelection
 * @uses   \OCA\Dossiq\Review\DocumentRelevance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class BatchSelectionTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The selection under test.
	 *
	 * @var BatchSelection
	 */
	private BatchSelection $selection;

	/**
	 * Four documents on case X: two held by A. de Wit, one marked by the rule "Nieuwsbrieven".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$rows = [
			'io-1' => ['A. de Wit', 'sharepoint', 'Nieuwsbrieven'],
			'io-2' => ['A. de Wit', 'files', ''],
			'io-3' => ['B. Smit', 'sharepoint', 'Nieuwsbrieven'],
			'io-4' => ['B. Smit', 'files', ''],
		];
		foreach ($rows as $id => [$custodian, $system, $rule]) {
			$this->store->seed(schema: 'informatieobject', uuid: $id, row: ['title' => $id, 'provenance' => ['source' => $system, 'sourceSystem' => $system, 'custodian' => $custodian]]);
			$this->store->seed(schema: 'zaakinformatieobject', uuid: 'join-'.$id, row: ['case' => 'case-x', 'informatieobject' => $id]);
			$review = ['case' => 'case-x', 'documentRef' => $id, 'relevance' => 'unmarked'];
			if ($rule !== '') {
				$review = ['case' => 'case-x', 'documentRef' => $id, 'relevance' => 'out-of-scope', 'relevanceSource' => 'rule', 'rule' => $rule];
			}

			$this->store->seed(schema: 'documentReview', uuid: 'review-'.$id, row: $review);
		}

		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'dossier_informatieobject_schema' => 'informatieobject', 'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);

		$this->selection = new BatchSelection(
			reviews: new DocumentRelevance(settingsService: $settings, logger: $logger),
			provenance: new CaseDocumentProvenance(
				store: new DocumentRecordStore(settingsService: $settings),
				caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger),
			),
		);
	}//end setUp()

	/**
	 * Without a filter the listed documents are taken, each once.
	 *
	 * @return void
	 */
	public function testWithoutAFilterTheListedDocumentsAreTaken(): void {
		$this->assertSame(['io-1', 'io-2'], $this->selection->select(caseId: 'case-x', documents: ['io-1', 'io-2', 'io-1'], filter: []));
	}//end testWithoutAFilterTheListedDocumentsAreTaken()

	/**
	 * Custodian and source system read the provenance; every field must hold.
	 *
	 * @return void
	 */
	public function testCustodianAndSourceSystemReadTheProvenance(): void {
		$this->assertSame(['io-1', 'io-2'], $this->selection->select(caseId: 'case-x', documents: [], filter: ['custodian' => 'A. de Wit']));
		$this->assertSame(['io-1', 'io-3'], $this->selection->select(caseId: 'case-x', documents: [], filter: ['sourceSystem' => 'sharepoint']));
		$this->assertSame(['io-1'], $this->selection->select(caseId: 'case-x', documents: [], filter: ['custodian' => 'A. de Wit', 'sourceSystem' => 'sharepoint']));
	}//end testCustodianAndSourceSystemReadTheProvenance()

	/**
	 * A rule and a custodian together take the documents both say.
	 *
	 * @return void
	 */
	public function testARuleAndACustodianTogetherTakeTheirIntersection(): void {
		$this->assertSame(['io-1', 'io-3'], $this->selection->select(caseId: 'case-x', documents: [], filter: ['rule' => 'Nieuwsbrieven']));
		$this->assertSame(['io-3'], $this->selection->select(caseId: 'case-x', documents: [], filter: ['rule' => 'Nieuwsbrieven', 'custodian' => 'B. Smit']));
	}//end testARuleAndACustodianTogetherTakeTheirIntersection()

	/**
	 * A field neither the review nor the provenance carries is refused, never an empty batch.
	 *
	 * @return void
	 */
	public function testAFilterOnAnUnknownFieldIsRefused(): void {
		try {
			$this->selection->select(caseId: 'case-x', documents: [], filter: ['department' => 'Verkeer']);
			$this->fail('A filter on a field no document carries answered a batch.');
		} catch (RefusedException $e) {
			$this->assertSame('review-batch-filter-unknown', $e->getRule());
			$this->assertSame(422, $e->getStatus());
		}
	}//end testAFilterOnAnUnknownFieldIsRefused()

	/**
	 * The fields are the review's rule plus the provenance's filter fields.
	 *
	 * @return void
	 */
	public function testTheFieldsAreTheRuleAndTheProvenanceFields(): void {
		$this->assertSame(['rule', 'custodian', 'sourceSystem', 'source'], BatchSelection::fields());
	}//end testTheFieldsAreTheRuleAndTheProvenanceFields()

	/**
	 * A provenance that cannot be read refuses the batch instead of answering an empty one.
	 *
	 * @return void
	 */
	public function testAnUnreadableProvenanceRefusesTheBatch(): void {
		$provenance = $this->createMock(CaseDocumentProvenance::class);
		$provenance->method('select')->willThrowException(new \RuntimeException('schema not configured'));
		$selection = new BatchSelection(reviews: $this->createMock(DocumentRelevance::class), provenance: $provenance);

		try {
			$selection->select(caseId: 'case-x', documents: [], filter: ['custodian' => 'A. de Wit']);
			$this->fail('An unreadable provenance answered a batch.');
		} catch (RefusedException $e) {
			$this->assertSame('review-batch-unavailable', $e->getRule());
		}
	}//end testAnUnreadableProvenanceRefusesTheBatch()
}//end class
