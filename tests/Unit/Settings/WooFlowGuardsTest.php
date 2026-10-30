<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\DelegatedAuthorityGuard;
use OCA\Dossiq\Service\Transitions\DocumentAssessmentGuard;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\GuardResult;
use OCA\Dossiq\Service\Transitions\OfferedTransitions;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\MakesStatusDeclarations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The shipped Woo request flow, asked of the engine the case page reads.
 *
 * Live on 13 October a Woo request moved from Ontvangst to Publicatie with
 * nothing but a free-text reason each time: no documents, no assessment, no
 * decision. The case type shipped no flow, so the backfill wrote a linear
 * one with no guards. These cases put the shipped flow through the real
 * `OfferedTransitions`, `GuardRegistry`, `DocumentAssessmentGuard`,
 * `TransitionDeclarations` and `TransitionPreconditions`; only the reads of
 * the register are doubles.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
class WooFlowGuardsTest extends TestCase {
	use MakesStatusDeclarations;

	private const CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * The documents under review on the case, per the assessment service.
	 *
	 * @var array<int, string>
	 */
	private array $underReview = [];

	/**
	 * The documents still waiting for an assessment.
	 *
	 * @var array<int, string>
	 */
	private array $outstanding = [];

	/**
	 * The partly public documents still waiting for a redacted copy.
	 *
	 * @var array<int, string>
	 */
	private array $waitingForCopy = [];

	/**
	 * Whether the register cannot be read.
	 *
	 * @var bool
	 */
	private bool $unreadable = false;

	/**
	 * The shipped flow's transitions.
	 *
	 * @return array<int, array<string, mixed>> The transitions, decoded.
	 */
	private function shippedTransitions(): array {
		$flow = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/packages/woo-verzoek/flows/woo-verzoek.json'),
			true
		);
		$this->assertIsArray($flow);
		$this->assertSame(expected: self::CASE_TYPE, actual: $flow['caseType']);

		return (array)json_decode((string)$flow['transitions'], true);
	}//end shippedTransitions()

	/**
	 * The status uuid of the Woo case type's n-th status.
	 *
	 * @param int $order The status order, 1 to 8.
	 *
	 * @return string The uuid.
	 */
	private function statusId(int $order): string {
		return '3c0f5a00-0000-4000-a000-00000000b00' . $order;
	}//end statusId()

	/**
	 * The engine the case page asks what it may offer.
	 *
	 * @return OfferedTransitions The offer.
	 */
	private function offer(): OfferedTransitions {
		$assessments = $this->createMock(originalClassName: WOODocumentAssessmentService::class);
		$assessments->method('getDocumentsUnderReview')->willReturnCallback(
			function (): array {
				if ($this->unreadable === true) {
					throw new RuntimeException('OpenRegister is not available');
				}

				return $this->underReview;
			}
		);
		$assessments->method('getOutstanding')->willReturnCallback(
			fn (): array => ['count' => count($this->outstanding), 'documents' => $this->outstanding]
		);
		$assessments->method('partlyPublicWithoutCopy')->willReturnCallback(
			fn (): array => ['partlyPublic' => max(1, count($this->waitingForCopy)), 'waiting' => $this->waitingForCopy]
		);

		$passing = new GuardResult(passed: true);
		$statusChecklist = $this->createMock(originalClassName: StatusChecklistGuard::class);
		$statusChecklist->method('evaluate')->willReturn($passing);
		$capacity = $this->createMock(originalClassName: CapacityGuard::class);
		$capacity->method('evaluate')->willReturn($passing);

		$registry = new GuardRegistry(
			$this->createMock(originalClassName: ChecklistGuard::class),
			new RequiredFieldGuard(l10n: new SourceL10n()),
			new RequiredDocumentGuard(l10n: new SourceL10n()),
			$this->createMock(originalClassName: RoleGuard::class),
			$this->createMock(originalClassName: DelegatedAuthorityGuard::class),
			$statusChecklist,
			$capacity,
			new NullLogger(),
			null,
			new DocumentAssessmentGuard(assessments: $assessments, logger: new NullLogger(), l10n: new SourceL10n()),
		);

		$resultWriter = $this->createMock(originalClassName: CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturn(false);

		$moves = new TransitionDeclarations(
			preconditions: new TransitionPreconditions(
				obligations: $this->createMock(originalClassName: ObligationService::class),
				declaration: new ObligationDeclaration(),
				evaluator: new DerivedStatusEvaluator(declaration: new StatusDeclaration()),
			),
			fourEyes: new FourEyesRule(),
			resultWriter: $resultWriter,
			store: $this->createMock(originalClassName: CaseStatusStore::class),
		);

		return new OfferedTransitions(
			guardRegistry: $registry,
			specReader: new TransitionSpecReader(),
			statuses: $this->undeclaredStatuses(),
			moves: $moves,
		);
	}//end offer()

	/**
	 * What the case page reads for a Woo request in the given status.
	 *
	 * @param int                  $order  The status order.
	 * @param array<string, mixed> $fields Extra case fields.
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, withheld: array<int, array<string, mixed>>}
	 */
	private function answerIn(int $order, array $fields = []): array {
		$case = ['id' => 'woo-1', 'caseType' => self::CASE_TYPE, 'status' => $this->statusId(order: $order)] + $fields;

		return $this->offer()->from(
			transitions: $this->shippedTransitions(),
			case: $case,
			currentId: $this->statusId(order: $order),
			userId: 'thomas.kuiper',
		);
	}//end answerIn()

	/**
	 * The offered move to a status, or null.
	 *
	 * @param array<string, mixed> $answer The answer.
	 * @param int                  $to     The target status order.
	 *
	 * @return array<string, mixed>|null The move.
	 */
	private function moveTo(array $answer, int $to): ?array {
		foreach ($answer['transitions'] as $move) {
			if ($move['toStatus'] === $this->statusId(order: $to)) {
				return $move;
			}
		}

		return null;
	}//end moveTo()

	/**
	 * The withheld move to a status, or null.
	 *
	 * @param array<string, mixed> $answer The answer.
	 * @param int                  $to     The target status order.
	 *
	 * @return array<string, mixed>|null The withheld entry.
	 */
	private function withheldTo(array $answer, int $to): ?array {
		foreach ($answer['withheld'] as $entry) {
			if ($entry['toStatus'] === $this->statusId(order: $to)) {
				return $entry;
			}
		}

		return null;
	}//end withheldTo()

	/**
	 * The offered move with this label, or null.
	 *
	 * @param array<string, mixed> $answer The answer.
	 * @param string               $label  The move's label.
	 *
	 * @return array<string, mixed>|null The move.
	 */
	private function offeredNamed(array $answer, string $label): ?array {
		foreach ($answer['transitions'] as $move) {
			if ($move['label'] === $label) {
				return $move;
			}
		}

		return null;
	}//end offeredNamed()

	/**
	 * The withheld move with this label, or null.
	 *
	 * @param array<string, mixed> $answer The answer.
	 * @param string               $label  The move's label.
	 *
	 * @return array<string, mixed>|null The withheld entry.
	 */
	private function withheldNamed(array $answer, string $label): ?array {
		foreach ($answer['withheld'] as $entry) {
			if ($entry['label'] === $label) {
				return $entry;
			}
		}

		return null;
	}//end withheldNamed()

	/**
	 * Every status but the last leads on, and only the shipped statuses are named.
	 *
	 * @return void
	 */
	public function testTheFlowWalksTheEightStatusesOfTheCaseType(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-woo-verzoek.json'), true);
		$statuses = [];
		$caseType = [];
		foreach ($seed['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'statusType') {
				$statuses[] = $object['id'];
			}

			if ($object['@self']['schema'] === 'caseType') {
				$caseType = $object;
			}
		}

		$flow = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/packages/woo-verzoek/flows/woo-verzoek.json'), true);
		$this->assertSame(expected: $flow['id'], actual: $caseType['workflowDefinition'], message: 'the case type names the shipped flow as its default route');

		foreach ($this->shippedTransitions() as $move) {
			$this->assertContains(needle: $move['fromStatus'], haystack: $statuses);
			$this->assertContains(needle: $move['toStatus'], haystack: $statuses);
		}

		for ($order = 1; $order <= 7; $order++) {
			$leads = array_filter(
				$this->shippedTransitions(),
				fn (array $move): bool => $move['fromStatus'] === $this->statusId(order: $order)
					&& $move['toStatus'] === $this->statusId(order: $order + 1)
			);
			$this->assertCount(expectedCount: 1, haystack: $leads, message: 'status ' . $order . ' leads on');
		}
	}//end testTheFlowWalksTheEightStatusesOfTheCaseType()

	/**
	 * Assessing documents needs documents: with none gathered the move is refused.
	 *
	 * @return void
	 */
	public function testAssessingWaitsForGatheredDocuments(): void {
		$move = $this->moveTo(answer: $this->answerIn(order: 3), to: 4);
		$this->assertNotNull($move);
		$this->assertFalse(condition: $move['guardsPassed']);
		$this->assertSame(expected: 'No documents have been collected yet. Found nothing? Record that with an explanation', actual: $move['failedGuards'][0]['failureMessage']);

		$this->underReview = ['doc-1', 'doc-2'];
		$this->assertTrue(condition: $this->moveTo(answer: $this->answerIn(order: 3), to: 4)['guardsPassed']);
	}//end testAssessingWaitsForGatheredDocuments()

	/**
	 * The decision waits until every partly public document has a redacted copy.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
	 */
	public function testTheDecisionWaitsForEveryRedactedCopy(): void {
		$this->underReview = ['doc-1', 'doc-2'];
		$this->waitingForCopy = ['doc-2'];
		$move = $this->moveTo(answer: $this->answerIn(order: 5), to: 6);
		$this->assertFalse(condition: $move['guardsPassed']);
		$this->assertSame(
			expected: '1 of 1 partly public documents are still waiting for a redacted version',
			actual: $move['failedGuards'][0]['failureMessage']
		);

		$this->waitingForCopy = [];
		$this->assertTrue(condition: $this->moveTo(answer: $this->answerIn(order: 5), to: 6)['guardsPassed']);
	}//end testTheDecisionWaitsForEveryRedactedCopy()

	/**
	 * Redaction waits until every gathered document is assessed.
	 *
	 * @return void
	 */
	public function testRedactionWaitsForEveryAssessment(): void {
		$this->underReview = ['doc-1', 'doc-2'];
		$this->outstanding = ['doc-2'];
		$move = $this->moveTo(answer: $this->answerIn(order: 4), to: 5);
		$this->assertFalse(condition: $move['guardsPassed']);
		$this->assertSame(expected: '1 of 2 documents are still waiting for an assessment', actual: $move['failedGuards'][0]['failureMessage']);

		$this->outstanding = [];
		$this->assertTrue(condition: $this->moveTo(answer: $this->answerIn(order: 4), to: 5)['guardsPassed']);
	}//end testRedactionWaitsForEveryAssessment()

	/**
	 * An empty case is not "all assessed": the decision stage stays shut.
	 *
	 * @return void
	 */
	public function testNoDocumentsIsNotEveryDocumentAssessed(): void {
		$move = $this->moveTo(answer: $this->answerIn(order: 5), to: 6);
		$this->assertFalse(condition: $move['guardsPassed']);
	}//end testNoDocumentsIsNotEveryDocumentAssessed()

	/**
	 * Publication is withheld until the decision is made.
	 *
	 * @return void
	 */
	public function testPublicationWaitsForTheDecision(): void {
		$this->underReview = ['doc-1'];
		$answer = $this->answerIn(order: 6);
		$this->assertNull(actual: $this->moveTo(answer: $answer, to: 7), message: 'not offered without a decision');
		$this->assertSame(expected: ['het besluit (stel het besluit op)'], actual: $this->withheldTo(answer: $answer, to: 7)['reasons']);

		$this->assertTrue(
			condition: $this->moveTo(answer: $this->answerIn(order: 6, fields: ['wooPublicationStatus' => 'ready']), to: 7)['guardsPassed']
		);
	}//end testPublicationWaitsForTheDecision()

	/**
	 * Closing waits for the publication, not merely for a decision.
	 *
	 * @return void
	 */
	public function testClosingWaitsForThePublication(): void {
		$ready = $this->answerIn(order: 7, fields: ['wooPublicationStatus' => 'ready']);
		$this->assertNull(actual: $this->moveTo(answer: $ready, to: 8));
		$this->assertSame(expected: ['de publicatie van het besluit'], actual: $this->withheldTo(answer: $ready, to: 8)['reasons']);

		$this->assertNotNull(
			actual: $this->moveTo(answer: $this->answerIn(order: 7, fields: ['wooPublicationStatus' => 'published']), to: 8)
		);
	}//end testClosingWaitsForThePublication()

	/**
	 * A register that cannot be read refuses the move and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterRefuses(): void {
		$this->unreadable = true;
		$move = $this->moveTo(answer: $this->answerIn(order: 3), to: 4);
		$this->assertFalse(condition: $move['guardsPassed']);
		$this->assertSame(expected: 'The documents of this case could not be read', actual: $move['failedGuards'][0]['failureMessage']);
	}//end testAnUnreadableRegisterRefuses()

	/**
	 * The withdrawal has a direct move from every status it is open in, and waits for its reason.
	 *
	 * @return void
	 */
	public function testTheWithdrawalStaysReachableAndWaitsForItsReason(): void {
		foreach ([1, 2] as $order) {
			$bare = $this->answerIn(order: $order);
			$this->assertNull(actual: $this->offeredNamed(answer: $bare, label: 'Ingetrokken door de verzoeker'));
			$this->assertSame(
				expected: ['de reden van de intrekking'],
				actual: $this->withheldNamed(answer: $bare, label: 'Ingetrokken door de verzoeker')['reasons'],
			);

			$given = $this->answerIn(order: $order, fields: ['withdrawalReason' => 'Ik heb de stukken al gekregen.']);
			$move = $this->offeredNamed(answer: $given, label: 'Ingetrokken door de verzoeker');
			$this->assertNotNull(actual: $move, message: 'withdrawal from status ' . $order);
			$this->assertSame(expected: $this->statusId(order: 8), actual: $move['toStatus']);
		}
	}//end testTheWithdrawalStaysReachableAndWaitsForItsReason()

	/**
	 * D1: a recorded "nothing found" with its note walks the document stages.
	 *
	 * @return void
	 */
	public function testARecordedNothingFoundCountsAsAssessed(): void {
		$recorded = ['documentSearchOutcome' => 'nothing_found', 'documentSearchNote' => 'Gezocht in mail, zaaksysteem en archief 2025-2026.'];
		foreach ([[3, 4], [4, 5], [5, 6]] as [$from, $to]) {
			$move = $this->moveTo(answer: $this->answerIn(order: $from, fields: $recorded), to: $to);
			$this->assertTrue(condition: $move['guardsPassed'], message: $from . ' to ' . $to);
		}

		$noNote = $this->moveTo(answer: $this->answerIn(order: 3, fields: ['documentSearchOutcome' => 'nothing_found']), to: 4);
		$this->assertFalse(condition: $noNote['guardsPassed'], message: 'an outcome without a note is no record');
	}//end testARecordedNothingFoundCountsAsAssessed()

	/**
	 * D3: an inadmissible request closes from its admissibility check once the reason is recorded.
	 *
	 * @return void
	 */
	public function testAnInadmissibleRequestClosesWithItsReason(): void {
		$bare = $this->answerIn(order: 2);
		$this->assertNull(actual: $this->offeredNamed(answer: $bare, label: 'Niet ontvankelijk'));
		$withheld = $this->withheldNamed(answer: $bare, label: 'Niet ontvankelijk');
		$this->assertSame(expected: $this->statusId(order: 8), actual: $withheld['toStatus']);
		$this->assertSame(expected: ['de reden waarom het verzoek niet ontvankelijk is'], actual: $withheld['reasons']);

		$given = $this->answerIn(order: 2, fields: ['inadmissibilityReason' => 'Het verzoek gaat niet over documenten van de gemeente.']);
		$this->assertNotNull(actual: $this->offeredNamed(answer: $given, label: 'Niet ontvankelijk'));
	}//end testAnInadmissibleRequestClosesWithItsReason()

	/**
	 * Every way out of the flow that skips the decision waits for a recorded reason.
	 *
	 * @return void
	 */
	public function testEveryExitWithoutADecisionWaitsForAReason(): void {
		foreach ($this->shippedTransitions() as $move) {
			if ($move['toStatus'] !== $this->statusId(order: 8) || $move['fromStatus'] === $this->statusId(order: 7)) {
				continue;
			}

			$this->assertNotEmpty($move['requiresSettled'] ?? [], $move['label'] . ' closes the case without a reason');
		}
	}//end testEveryExitWithoutADecisionWaitsForAReason()
}//end class
