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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
}//end class
