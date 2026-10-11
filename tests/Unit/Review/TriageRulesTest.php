<?php

/**
 * Tests for TriageRules: visible rules that mark unmarked documents without opening them.
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
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Review\TriageConditions;
use OCA\Dossiq\Review\TriageRules;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Review\TriageRules
 * @uses   \OCA\Dossiq\Review\TriageConditions
 * @uses   \OCA\Dossiq\Review\DocumentRelevance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageRulesTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The extracted text by file id.
	 *
	 * @var array<int, string>
	 */
	private array $texts = [];

	/**
	 * The text double.
	 *
	 * @var DocumentText&MockObject
	 */
	private DocumentText $text;

	/**
	 * The reviewer access double.
	 *
	 * @var CaseReviewerAccess&MockObject
	 */
	private CaseReviewerAccess $access;

	/**
	 * An empty register; documents are added per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->access = $this->createMock(CaseReviewerAccess::class);
		$this->text = $this->createMock(DocumentText::class);
		$this->text->method('of')->willReturnCallback(fn (array $document): ?string => ($this->texts[(int)($document['fileId'] ?? 0)] ?? null));
	}//end setUp()

	/**
	 * Add a document to case X with its provenance and, optionally, its review.
	 *
	 * @param string               $id         The document uuid.
	 * @param array<string, mixed> $provenance The provenance on its record.
	 * @param array<string, mixed> $record     More fields of its record.
	 * @param array<string, mixed>|null $review Its review, or null for none.
	 *
	 * @return void
	 */
	private function document(string $id, array $provenance = [], array $record = [], ?array $review = null): void {
		$this->store->seed(schema: 'informatieobject', uuid: $id, row: ($record + ['title' => $id, 'provenance' => $provenance]));
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'join-'.$id, row: ['case' => 'case-x', 'informatieobject' => $id]);
		if ($review !== null) {
			$this->store->seed(schema: 'documentReview', uuid: 'review-'.$id, row: ($review + ['case' => 'case-x', 'documentRef' => $id]));
		}
	}//end document()

	/**
	 * A mail from an address, with no review.
	 *
	 * @param string $id   The document uuid.
	 * @param string $from The sender.
	 *
	 * @return void
	 */
	private function mail(string $id, string $from): void {
		$this->document(id: $id, provenance: ['source' => 'files', 'mail' => ['from' => $from, 'to' => ['b@gemeente.nl']]], record: ['format' => 'message/rfc822']);
	}//end mail()

	/**
	 * The rules service over the store.
	 *
	 * @return TriageRules
	 */
	private function rules(): TriageRules {
		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'triage_rule_schema' => 'triageRule', 'dossier_informatieobject_schema' => 'informatieobject', 'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);

		return new TriageRules(
			settingsService: $settings,
			reviews: new DocumentRelevance(settingsService: $settings, logger: $logger),
			caseDocuments: $caseDocuments,
			provenance: new CaseDocumentProvenance(store: new DocumentRecordStore(settingsService: $settings), caseDocuments: $caseDocuments),
			text: $this->text,
			conditions: new TriageConditions(),
			reviewerAccess: $this->access,
			logger: $logger,
		);
	}//end rules()

	/**
	 * The review of a document as stored.
	 *
	 * @param string $id The document uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function reviewOf(string $id): array {
		foreach ($this->store->all('documentReview') as $review) {
			if (($review['documentRef'] ?? '') === $id) {
				return $review;
			}
		}

		return [];
	}//end reviewOf()

	/**
	 * The scenario: "Nieuwsbrieven" sets aside 5 unmarked mails from its domain, and none was opened.
	 *
	 * @return void
	 */
	public function testARuleSetsAsideNewsletters(): void {
		for ($n = 1; $n <= 5; $n++) {
			$this->mail(id: 'mail-'.$n, from: 'redactie@nieuwsbrief.example.nl');
		}

		$this->mail(id: 'mail-other', from: 'a.dewit@gemeente.nl');
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$this->text->expects($this->never())->method('of');

		$applied = $rules->apply(caseId: 'case-x');

		$this->assertSame(['Nieuwsbrieven' => 5], $applied['counts']);
		$this->assertSame(6, $applied['considered']);
		for ($n = 1; $n <= 5; $n++) {
			$review = $this->reviewOf(id: 'mail-'.$n);
			$this->assertSame('out-of-scope', $review['relevance']);
			$this->assertSame('rule', $review['relevanceSource']);
			$this->assertSame('Nieuwsbrieven', $review['rule']);
		}

		$this->assertSame([], $this->reviewOf(id: 'mail-other'), 'a document no rule matched keeps no review');
	}//end testARuleSetsAsideNewsletters()

	/**
	 * Rules run in order and the first match decides.
	 *
	 * @return void
	 */
	public function testTheFirstMatchingRuleDecides(): void {
		$this->document(id: 'doc-1', provenance: ['source' => 'files', 'custodian' => 'A. de Wit']);
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Tweede', 'conditions' => ['custodian' => 'A. de Wit'], 'effect' => 'out-of-scope', 'order' => 2]);
		$rules->save(caseId: 'case-x', input: ['name' => 'Eerste', 'conditions' => ['custodian' => 'A. de Wit'], 'effect' => 'in-scope', 'order' => 1]);

		$applied = $rules->apply(caseId: 'case-x');

		$this->assertSame(1, $applied['counts']['Eerste']);
		$this->assertSame(0, $applied['counts']['Tweede']);
		$this->assertSame('in-scope', $this->reviewOf(id: 'doc-1')['relevance']);
	}//end testTheFirstMatchingRuleDecides()

	/**
	 * A rule never changes a reviewer's marking, also not one set back to unmarked.
	 *
	 * @return void
	 */
	public function testARuleNeverChangesAReviewersMarking(): void {
		$this->document(id: 'doc-in', provenance: ['custodian' => 'A. de Wit'], review: ['relevance' => 'in-scope', 'relevanceSource' => 'reviewer']);
		$this->document(id: 'doc-back', provenance: ['custodian' => 'A. de Wit'], review: ['relevance' => 'unmarked', 'relevanceSource' => 'reviewer']);
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Persoon', 'conditions' => ['custodian' => 'A. de Wit'], 'effect' => 'out-of-scope']);

		$applied = $rules->apply(caseId: 'case-x');

		$this->assertSame(0, $applied['marked']);
		$this->assertSame('in-scope', $this->reviewOf(id: 'doc-in')['relevance']);
		$this->assertSame('unmarked', $this->reviewOf(id: 'doc-back')['relevance']);
	}//end testARuleNeverChangesAReviewersMarking()

	/**
	 * A reviewer's overturn records the rule, applying again leaves it, and the rule list counts it.
	 *
	 * @return void
	 */
	public function testAnOverturnRecordsTheRule(): void {
		$this->mail(id: 'mail-1', from: 'redactie@nieuwsbrief.example.nl');
		$this->mail(id: 'mail-2', from: 'redactie@nieuwsbrief.example.nl');
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$rules->apply(caseId: 'case-x');

		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		(new DocumentRelevance(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)))
			->mark(caseId: 'case-x', documentRef: 'mail-1', relevance: 'in-scope', userId: 'reviewer-a');

		$again = $rules->apply(caseId: 'case-x');

		$review = $this->reviewOf(id: 'mail-1');
		$this->assertSame('in-scope', $review['relevance']);
		$this->assertSame('reviewer', $review['relevanceSource']);
		$this->assertSame('Nieuwsbrieven', $review['overturnedRule']);
		$this->assertSame(0, $again['marked']);

		$listed = $rules->forCase(caseId: 'case-x');
		$this->assertSame(1, $listed[0]['decided']);
		$this->assertSame(1, $listed[0]['overturned']);
	}//end testAnOverturnRecordsTheRule()

	/**
	 * A document without extracted text matches no text condition, the contains nor the not-contains one.
	 *
	 * @return void
	 */
	public function testADocumentWithoutTextNeverMatchesATextCondition(): void {
		$this->document(id: 'doc-text', record: ['fileId' => 11]);
		$this->document(id: 'doc-none', record: ['fileId' => 12]);
		$this->texts = [11 => 'Overleg over het fietspad langs de Vaart'];
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Fietspad', 'conditions' => ['textContains' => 'fietspad'], 'effect' => 'in-scope', 'order' => 1]);
		$rules->save(caseId: 'case-x', input: ['name' => 'Geen fietspad', 'conditions' => ['textNotContains' => 'fietspad'], 'effect' => 'out-of-scope', 'order' => 2]);

		$applied = $rules->apply(caseId: 'case-x');

		$this->assertSame(['Fietspad' => 1, 'Geen fietspad' => 0], $applied['counts']);
		$this->assertSame([], $this->reviewOf(id: 'doc-none'));
	}//end testADocumentWithoutTextNeverMatchesATextCondition()

	/**
	 * Custodian, source system, file type and a date range read the provenance and the record together.
	 *
	 * @return void
	 */
	public function testARuleReadsProvenanceTypeAndDate(): void {
		$this->document(id: 'doc-old', provenance: ['source' => 'sharepoint', 'custodian' => 'A. de Wit'], record: ['format' => 'application/pdf', 'creatiedatum' => '2024-06-01']);
		$this->document(id: 'doc-new', provenance: ['source' => 'sharepoint', 'custodian' => 'A. de Wit'], record: ['format' => 'application/pdf', 'creatiedatum' => '2025-06-01']);
		$this->document(id: 'doc-cal', provenance: ['source' => 'files', 'custodian' => 'A. de Wit'], record: ['format' => 'text/calendar', 'creatiedatum' => '2025-06-01']);
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Buiten de periode', 'conditions' => ['custodian' => 'A. de Wit', 'sourceSystem' => 'sharepoint', 'mimeType' => 'application/*', 'dateTo' => '2024-12-31'], 'effect' => 'out-of-scope']);

		$applied = $rules->apply(caseId: 'case-x');

		$this->assertSame(1, $applied['marked']);
		$this->assertSame('out-of-scope', $this->reviewOf(id: 'doc-old')['relevance']);
		$this->assertSame([], $this->reviewOf(id: 'doc-new'));
		$this->assertSame([], $this->reviewOf(id: 'doc-cal'));
	}//end testARuleReadsProvenanceTypeAndDate()

	/**
	 * An inactive rule does not run, and without active rules nothing is read.
	 *
	 * @return void
	 */
	public function testAnInactiveRuleDoesNotRun(): void {
		$this->mail(id: 'mail-1', from: 'redactie@nieuwsbrief.example.nl');
		$rules = $this->rules();
		$saved = $rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$rules->save(caseId: 'case-x', input: ['active' => false], ruleId: (string)$saved['id']);

		$this->assertSame(['counts' => [], 'marked' => 0, 'considered' => 0], $rules->apply(caseId: 'case-x'));
		$this->assertSame('Nieuwsbrieven', $rules->forCase(caseId: 'case-x')[0]['name'], 'a change keeps what it does not name');
	}//end testAnInactiveRuleDoesNotRun()

	/**
	 * An incomplete rule, a taken name and another case's rule are refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnIncompleteRuleIsRefused(): void {
		$rules = $this->rules();
		$rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$writes = $this->store->writes;
		$cases = [
			['triage-rule-name-required', ['name' => ' ', 'conditions' => ['custodian' => 'A'], 'effect' => 'in-scope'], 422],
			['triage-rule-effect-required', ['name' => 'X', 'conditions' => ['custodian' => 'A']], 422],
			['triage-rule-condition-required', ['name' => 'X', 'conditions' => [], 'effect' => 'in-scope'], 422],
			['triage-rule-condition-unknown', ['name' => 'X', 'conditions' => ['department' => 'A'], 'effect' => 'in-scope'], 422],
			['triage-rule-name-taken', ['name' => 'Nieuwsbrieven', 'conditions' => ['custodian' => 'A'], 'effect' => 'in-scope'], 409],
		];
		foreach ($cases as [$rule, $input, $status]) {
			try {
				$rules->save(caseId: 'case-x', input: $input);
				$this->fail('Expected '.$rule);
			} catch (RefusedException $e) {
				$this->assertSame($rule, $e->getRule());
				$this->assertSame($status, $e->getStatus());
			}
		}

		try {
			$rules->save(caseId: 'case-y', input: ['active' => false], ruleId: 'generated-1');
			$this->fail('Another case changed the rule.');
		} catch (RefusedException $e) {
			$this->assertSame('triage-rule-unknown', $e->getRule());
			$this->assertSame(404, $e->getStatus());
		}

		$this->assertSame($writes, $this->store->writes);
	}//end testAnIncompleteRuleIsRefused()

	/**
	 * A deleted rule is gone, and the documents it marked keep their marking.
	 *
	 * @return void
	 */
	public function testADeletedRuleLeavesItsMarkings(): void {
		$this->mail(id: 'mail-1', from: 'redactie@nieuwsbrief.example.nl');
		$rules = $this->rules();
		$saved = $rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$rules->apply(caseId: 'case-x');

		$rules->delete(caseId: 'case-x', ruleId: (string)$saved['id']);

		$this->assertSame([], $rules->forCase(caseId: 'case-x'));
		$this->assertSame('Nieuwsbrieven', $this->reviewOf(id: 'mail-1')['rule']);
	}//end testADeletedRuleLeavesItsMarkings()

	/**
	 * A created rule reconciles the case's reviewer access; a change does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function testACreatedRuleReconcilesTheCase(): void {
		$this->access->expects($this->once())->method('reconcile')->with('case-x')->willThrowException(new \RuntimeException('sharing down'));
		$rules = $this->rules();

		$saved = $rules->save(caseId: 'case-x', input: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope']);
		$rules->save(caseId: 'case-x', input: ['order' => 3], ruleId: (string)$saved['id']);

		$this->assertSame(3, $rules->forCase(caseId: 'case-x')[0]['order'], 'a failed reconcile keeps the rule');
	}//end testACreatedRuleReconcilesTheCase()
}//end class
