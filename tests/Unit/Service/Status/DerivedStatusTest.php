<?php

/**
 * A status that declares what makes it true.
 *
 * The assertion that earns its place here is the one about the status that
 * has NOT fired: a derivation that never arrives, with no reason on the case,
 * is worse than no derivation at all, and it is the only half of this feature
 * a person cannot see by looking at the case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\DerivedStatusService;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\DerivedStatusService
 * @covers \OCA\Dossiq\Service\Status\DerivedStatusEvaluator
 * @covers \OCA\Dossiq\Service\Status\StatusDeclaration
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\FilledValue
 */
class DerivedStatusTest extends TestCase {

	/**
	 * The case type under test: Received is picked, Complete is derived.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statusTypes(): array {
		return [
			[
				'id' => 'received',
				'name' => 'Received',
				'order' => 1,
			],
			[
				'id' => 'complete',
				'name' => 'Complete',
				'order' => 2,
				'derivedWhen' => [
					['kind' => 'fieldPresent', 'field' => 'aanvulling.antwoord', 'label' => 'the intake form'],
					['kind' => 'documentPresent', 'documentType' => 'consent', 'label' => 'the signed consent form'],
					['kind' => 'documentPresent', 'documentType' => 'drawing', 'label' => 'the site drawing'],
				],
			],
			[
				'id' => 'decision',
				'name' => 'Decision',
				'order' => 3,
			],
		];
	}

	/**
	 * A service reading the case type above.
	 *
	 * @return DerivedStatusService
	 */
	private function service(): DerivedStatusService {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturn($this->statusTypes());

		$declaration = new StatusDeclaration();

		return new DerivedStatusService(
			caseTypes: $resolver,
			declaration: $declaration,
			evaluator: new DerivedStatusEvaluator(declaration: $declaration),
		);
	}

	/**
	 * The case becomes complete when the file is complete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testTheCaseMovesWhenEveryConditionHolds(): void {
		$case = [
			'caseType' => 'building-permit',
			'status' => 'received',
			'aanvulling' => ['antwoord' => 'yes'],
			'documents' => [
				['documentType' => 'consent'],
				['documentType' => 'drawing'],
			],
		];

		self::assertSame(
			expected: 'complete',
			actual: $this->service()->statusFor(case: $case),
		);
	}//end testTheCaseMovesWhenEveryConditionHolds()

	/**
	 * An unmet derivation says what is missing, in the author's own words.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAnUnmetDerivationNamesTheMissingDocument(): void {
		$case = [
			'caseType' => 'building-permit',
			'status' => 'received',
			'aanvulling' => ['antwoord' => 'yes'],
			'documents' => [['documentType' => 'consent']],
		];

		$service = $this->service();

		self::assertNull(actual: $service->statusFor(case: $case));

		$reason = $service->blockingReasonFor(case: $case);
		self::assertNotNull(actual: $reason);
		self::assertSame(expected: 'Complete', actual: $reason['name']);
		self::assertSame(expected: ['the site drawing'], actual: $reason['unmet']);
	}//end testAnUnmetDerivationNamesTheMissingDocument()

	/**
	 * An empty string is an unanswered question, not an answer.
	 *
	 * The direction that matters: a case whose form field holds '' would
	 * otherwise derive as complete, which is wrong in the one way nobody
	 * checks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAnEmptyFieldDoesNotSatisfyFieldPresent(): void {
		$case = [
			'caseType' => 'building-permit',
			'status' => 'received',
			'aanvulling' => ['antwoord' => '   '],
			'documents' => [['documentType' => 'consent'], ['documentType' => 'drawing']],
		];

		$reason = $this->service()->blockingReasonFor(case: $case);
		self::assertNotNull(actual: $reason);
		self::assertSame(expected: ['the intake form'], actual: $reason['unmet']);
	}//end testAnEmptyFieldDoesNotSatisfyFieldPresent()

	/**
	 * A derivation never drags a case backwards.
	 *
	 * A case already at Decision satisfies Complete's conditions every time a
	 * document is touched. Moving it back would undo a handler's work on every
	 * save, silently.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testALaterStatusIsNotPulledBackToAnEarlierDerivedOne(): void {
		$case = [
			'caseType' => 'building-permit',
			'status' => 'decision',
			'aanvulling' => ['antwoord' => 'yes'],
			'documents' => [['documentType' => 'consent'], ['documentType' => 'drawing']],
		];

		self::assertNull(actual: $this->service()->statusFor(case: $case));
	}//end testALaterStatusIsNotPulledBackToAnEarlierDerivedOne()

	/**
	 * A derived status is one the engine must not offer as a choice; a status
	 * that declares nothing still is.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testOnlyTheDeclaringStatusCountsAsDerived(): void {
		$service = $this->service();

		self::assertTrue(
			condition: $service->isDerivedStatus(caseTypeId: 'building-permit', statusTypeId: 'complete'),
		);
		self::assertFalse(
			condition: $service->isDerivedStatus(caseTypeId: 'building-permit', statusTypeId: 'decision'),
		);
	}//end testOnlyTheDeclaringStatusCountsAsDerived()

	/**
	 * A condition kind this install does not know is dropped, not failed.
	 *
	 * A declaration written for a later version of the vocabulary must not
	 * hold every case in the status before it with a reason nobody can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAnUnknownConditionKindIsDropped(): void {
		$declaration = new StatusDeclaration();
		$conditions = $declaration->derivedWhen(
			statusType: ['derivedWhen' => [
				['kind' => 'moonPhase', 'value' => 'waxing'],
				['kind' => 'fieldPresent', 'field' => 'title'],
			]],
		);

		self::assertCount(expectedCount: 1, haystack: $conditions);
		self::assertSame(expected: 'fieldPresent', actual: $conditions[0]['kind']);
	}//end testAnUnknownConditionKindIsDropped()

	/**
	 * A service over the given rows, for the shapes the store can answer.
	 *
	 * @param array<int, mixed> $rows The statusType rows the resolver answers.
	 *
	 * @return DerivedStatusService
	 */
	private function serviceOver(array $rows): DerivedStatusService {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturn($rows);

		$declaration = new StatusDeclaration();

		return new DerivedStatusService(
			caseTypes: $resolver,
			declaration: $declaration,
			evaluator: new DerivedStatusEvaluator(declaration: $declaration),
		);
	}//end serviceOver()

	/**
	 * A case with no case type has no verdicts and asks the store nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testACaseWithoutACaseTypeHasNoVerdicts(): void {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->expects($this->never())->method('statusTypesFor');

		$declaration = new StatusDeclaration();
		$service = new DerivedStatusService(
			caseTypes: $resolver,
			declaration: $declaration,
			evaluator: new DerivedStatusEvaluator(declaration: $declaration),
		);

		self::assertSame(expected: [], actual: $service->verdictsFor(case: ['status' => 'received']));
		self::assertNull(actual: $service->statusFor(case: []));
		self::assertNull(actual: $service->blockingReasonFor(case: []));
	}//end testACaseWithoutACaseTypeHasNoVerdicts()

	/**
	 * Malformed rows are skipped, a uuid stands in for a missing id, and the
	 * verdicts come back in declared order rather than store order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testVerdictsSkipMalformedRowsAndFollowTheDeclaredOrder(): void {
		$service = $this->serviceOver(
			rows: [
				'not-a-row',
				[
					'uuid' => 'late-uuid',
					'title' => 'Late',
					'order' => 5,
					'derivedWhen' => [['kind' => 'fieldPresent', 'field' => 'b', 'label' => 'b']],
				],
				[
					'name' => 'No id',
					'order' => 1,
					'derivedWhen' => [['kind' => 'fieldPresent', 'field' => 'a']],
				],
				[
					'id' => 'early',
					'name' => 'Early',
					'order' => 2,
					'derivedWhen' => [['kind' => 'fieldPresent', 'field' => 'a', 'label' => 'a']],
				],
			]
		);

		$verdicts = $service->verdictsFor(case: ['caseType' => 'ct', 'a' => 'set']);

		self::assertSame(
			expected: [
				['statusId' => 'early', 'name' => 'Early', 'order' => 2, 'satisfied' => true, 'unmet' => []],
				['statusId' => 'late-uuid', 'name' => 'Late', 'order' => 5, 'satisfied' => false, 'unmet' => ['b']],
			],
			actual: $verdicts,
		);
	}//end testVerdictsSkipMalformedRowsAndFollowTheDeclaredOrder()

	/**
	 * An empty argument, an unknown status and a malformed row all answer
	 * "not derived", so the engine keeps offering the status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAnUnresolvableStatusIsNotDerived(): void {
		$service = $this->serviceOver(rows: array_merge(['not-a-row'], $this->statusTypes()));

		self::assertFalse(condition: $service->isDerivedStatus(caseTypeId: '', statusTypeId: 'complete'));
		self::assertFalse(condition: $service->isDerivedStatus(caseTypeId: 'building-permit', statusTypeId: ''));
		self::assertFalse(condition: $service->isDerivedStatus(caseTypeId: 'building-permit', statusTypeId: 'unknown'));
		self::assertTrue(condition: $service->isDerivedStatus(caseTypeId: 'building-permit', statusTypeId: 'complete'));
	}//end testAnUnresolvableStatusIsNotDerived()

	/**
	 * A case already sitting in the status that derives is not moved again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testACaseAlreadyInTheDerivedStatusIsNotMoved(): void {
		$case = [
			'caseType' => 'building-permit',
			'status' => 'complete',
			'aanvulling' => ['antwoord' => 'yes'],
			'documents' => [['documentType' => 'consent'], ['documentType' => 'drawing']],
		];

		self::assertNull(actual: $this->service()->statusFor(case: $case));
	}//end testACaseAlreadyInTheDerivedStatusIsNotMoved()

	/**
	 * With every derivation met there is nothing blocking; a derivation
	 * earlier than where the case already is does not block it either.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testNothingBlocksWhenNoLaterDerivationIsUnmet(): void {
		$complete = [
			'caseType' => 'building-permit',
			'status' => 'received',
			'aanvulling' => ['antwoord' => 'yes'],
			'documents' => [['documentType' => 'consent'], ['documentType' => 'drawing']],
		];
		self::assertNull(actual: $this->service()->blockingReasonFor(case: $complete));

		$pastIt = [
			'caseType' => 'building-permit',
			'status' => 'decision',
			'documents' => [],
		];
		self::assertNull(actual: $this->service()->blockingReasonFor(case: $pastIt));
	}//end testNothingBlocksWhenNoLaterDerivationIsUnmet()

	/**
	 * A current status the case type does not know counts as the start, so
	 * the earliest unmet derivation still blocks; malformed rows are skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAnUnknownCurrentStatusIsReadAsTheStart(): void {
		$service = $this->serviceOver(rows: array_merge(['not-a-row'], $this->statusTypes()));

		$reason = $service->blockingReasonFor(
			case: ['caseType' => 'building-permit', 'status' => 'retired-status', 'documents' => []],
		);

		self::assertSame(expected: 'complete', actual: $reason['statusId'] ?? null);
		self::assertSame(
			expected: ['the intake form', 'the signed consent form', 'the site drawing'],
			actual: $reason['unmet'] ?? null,
		);
	}//end testAnUnknownCurrentStatusIsReadAsTheStart()

	/**
	 * A condition that is not an array, or a `derivedWhen` that is not a list,
	 * declares nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function testAMalformedConditionIsSkipped(): void {
		$declaration = new StatusDeclaration();

		self::assertSame(
			expected: [],
			actual: $declaration->derivedWhen(statusType: ['derivedWhen' => 'fieldPresent']),
		);
		self::assertSame(
			expected: [
				[
					'kind' => 'fieldEquals',
					'field' => 'outcome',
					'value' => 'granted',
					'documentType' => '',
					'label' => '',
				],
			],
			actual: $declaration->derivedWhen(
				statusType: ['derivedWhen' => ['fieldPresent', ['kind' => 'fieldEquals', 'field' => 'outcome', 'value' => 'granted']]],
			),
		);
	}//end testAMalformedConditionIsSkipped()
}//end class
