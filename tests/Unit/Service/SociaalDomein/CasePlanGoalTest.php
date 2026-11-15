<?php

/**
 * Unit tests for CasePlanGoals.
 *
 * 🔴 WHAT THESE GUARD IS A PLAN NOBODY CAN EVALUATE. The register held goals as
 * plain strings, so "de thuissituatie verbeteren" was a legal goal and no
 * review could ever close it: there was nothing to decide against. `metWhen` is
 * therefore required ON SAVE and not only in a form, because a required field
 * in a dialog is a field an API call does not have to send, and closing a goal
 * requires an observation measured against it.
 *
 * The test that matters most is the refusal, and that the refusal QUOTES the
 * metWhen when a goal is closed with nothing written: the person closing it has
 * to be told what they are supposed to be comparing against.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CasePlanGoals.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\CasePlanGoals
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\SettingsService
 */
class CasePlanGoalTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The service under test.
	 *
	 * @var CasePlanGoals
	 */
	private CasePlanGoals $goals;

	/**
	 * A plan with nothing on it yet.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed('gezinsplan', ['id' => 'plan-1', 'caseId' => 'case-1', 'preparedBy' => 'anna']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);

		$this->goals = new CasePlanGoals(
			store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger())
		);
	}//end setUp()

	/**
	 * A goal naming what would count as met is saved and comes back open.
	 *
	 * @return void
	 */
	public function testAGoalThatCanBeEvaluatedIsSaved(): void {
		$saved = $this->goals->save(
			goal: [
				'plan' => 'plan-1',
				'title' => 'Sem gaat weer naar school',
				'metWhen' => 'Sem is vier weken achtereen minstens vier dagen per week op school geweest',
			]
		);

		self::assertSame('open', $saved['state']);
		self::assertSame('', $saved['migratedFrom']);
		self::assertCount(1, $this->goals->ofPlan(planId: 'plan-1'));
	}//end testAGoalThatCanBeEvaluatedIsSaved()

	/**
	 * 🔴 A goal naming nothing measurable is refused on save.
	 *
	 * @return void
	 */
	public function testAGoalWithNothingMeasurableIsRefused(): void {
		try {
			$this->goals->save(
				goal: ['plan' => 'plan-1', 'title' => 'De thuissituatie verbeteren']
			);
			self::fail('A goal that names nothing measurable must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('goal-needs-what-counts-as-met', $e->getRule());
			self::assertStringContainsString('no review can close', $e->getSentence());
		}

		// And nothing was written, so the plan does not gain a goal that the
		// refusal said could not exist.
		self::assertSame([], $this->objects->rowsOf('casePlanGoal'));
	}//end testAGoalWithNothingMeasurableIsRefused()

	/**
	 * A goal with neither a plan nor a case, and a goal with no title, are refused too.
	 *
	 * @return void
	 */
	public function testAGoalNeedsAnOwnerAndATitle(): void {
		try {
			$this->goals->save(goal: ['title' => 'x', 'metWhen' => 'y']);
			self::fail(message: 'A goal with no plan and no case must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'goal-needs-an-owner', actual: $e->getRule());
		}

		try {
			$this->goals->save(goal: ['plan' => 'plan-1', 'metWhen' => 'y']);
			self::fail('A goal with no title must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('goal-needs-a-title', $e->getRule());
		}
	}//end testAGoalNeedsAnOwnerAndATitle()

	/**
	 * A goal can belong to a case directly (a Woo request, a permit), read back by the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-a-case-can-hold-goals-that-say-what-counts-as-met
	 */
	public function testAGoalCanBelongToACase(): void {
		$saved = $this->goals->save(
			goal: [
				'case' => 'case-woo',
				'title' => 'De verzoeker weet waarom de verlichting uitbleef',
				'metWhen' => 'Het besluit noemt de reden',
			]
		);

		self::assertSame(expected: 'open', actual: $saved['state']);
		self::assertSame(expected: 'case-woo', actual: $saved['case']);
		self::assertCount(expectedCount: 1, haystack: $this->goals->ofCase(caseId: 'case-woo'));
		self::assertSame(expected: [], actual: $this->goals->ofCase(caseId: 'case-other'));
		self::assertSame(expected: [], actual: $this->goals->ofCase(caseId: ' '));
		self::assertSame(expected: [], actual: $this->goals->ofPlan(planId: 'plan-1'));
	}//end testAGoalCanBelongToACase()

	/**
	 * 🔴 A review closes a goal with what was observed, against its metWhen.
	 *
	 * @return void
	 */
	public function testAGoalIsClosedWithTheObservation(): void {
		$saved = $this->goals->save(
			goal: [
				'plan' => 'plan-1',
				'title' => 'Sem gaat weer naar school',
				'metWhen' => 'Vier weken achtereen minstens vier dagen per week op school',
			]
		);

		$closed = $this->goals->close(
			goalId: (string)$saved['id'],
			state: 'met',
			observation: 'Sem is sinds 6 januari elke dag op school geweest',
			onDate: '2026-02-09',
		);

		self::assertSame('met', $closed['state']);
		self::assertSame('Sem is sinds 6 januari elke dag op school geweest', $closed['closedObservation']);
		self::assertSame('2026-02-09', $closed['closedDate']);

		// ONE goal, not two: closing replaces rather than creating.
		self::assertCount(1, $this->objects->rowsOf('casePlanGoal'));
	}//end testAGoalIsClosedWithTheObservation()

	/**
	 * 🔴 Closing with no observation is refused, and the refusal QUOTES metWhen.
	 *
	 * The person closing the goal has to be told what they are meant to be
	 * comparing against, or the required field is just an obstacle.
	 *
	 * @return void
	 */
	public function testClosingWithNoObservationIsRefusedAndQuotesTheMetWhen(): void {
		$saved = $this->goals->save(
			goal: [
				'plan' => 'plan-1',
				'title' => 'Sem gaat weer naar school',
				'metWhen' => 'Vier weken achtereen minstens vier dagen per week op school',
			]
		);

		try {
			$this->goals->close(goalId: (string)$saved['id'], state: 'met', observation: '   ');
			self::fail('Closing a goal with nothing observed must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('goal-close-needs-an-observation', $e->getRule());
			self::assertStringContainsString(
				'Vier weken achtereen minstens vier dagen per week op school',
				$e->getSentence()
			);
		}

		self::assertSame('open', $this->objects->rowsOf('casePlanGoal')[0]['state']);
	}//end testClosingWithNoObservationIsRefusedAndQuotesTheMetWhen()

	/**
	 * `open` is not a closing state, and neither is anything invented.
	 *
	 * @return void
	 */
	public function testAGoalIsNotClosedIntoAnOpenState(): void {
		$saved = $this->goals->save(
			goal: ['plan' => 'plan-1', 'title' => 'x', 'metWhen' => 'y']
		);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('goal_close_needs_a_closing_state');
		$this->goals->close(goalId: (string)$saved['id'], state: 'open', observation: 'iets');
	}//end testAGoalIsNotClosedIntoAnOpenState()

	/**
	 * 🔴 An unreadable store refuses rather than answering an empty plan.
	 *
	 * A plan that could not be read and a household with no goals look
	 * identical as an empty array, and a consulent shown an empty plan writes a
	 * new one over the plan that is already there.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreRefusesRatherThanAnsweringEmpty(): void {
		$this->objects->broken = true;

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('sociaal_domein_unreadable');
		$this->goals->ofPlan(planId: 'plan-1');
	}//end testAnUnreadableStoreRefusesRatherThanAnsweringEmpty()

	/**
	 * A goal of the case is closed through the case, with today as its day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testAGoalOfTheCaseIsClosedThroughTheCase(): void {
		$saved = $this->goals->save(goal: ['case' => 'case-1', 'title' => 'De verzoeker weet waarom', 'metWhen' => 'Het besluit noemt de reden']);

		$closed = $this->goals->closeOfCase(caseId: 'case-1', goalId: (string)$saved['id'], state: 'withdrawn', observation: 'De verzoeker trok het verzoek in');

		self::assertSame('withdrawn', $closed['state']);
		self::assertSame('De verzoeker trok het verzoek in', $closed['closedObservation']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $closed['closedDate']);
		self::assertCount(1, $this->objects->rowsOf('casePlanGoal'));
	}//end testAGoalOfTheCaseIsClosedThroughTheCase()

	/**
	 * 🔴 A goal of another case, or of a family plan only, is not found through this case and stays open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testAGoalOfAnotherOwnerIsNotFoundThroughTheCase(): void {
		$other = $this->goals->save(goal: ['case' => 'case-2', 'title' => 'Iets', 'metWhen' => 'Iets anders']);
		$planGoal = $this->goals->save(goal: ['plan' => 'plan-1', 'title' => 'Sem gaat naar school', 'metWhen' => 'Vier weken op school']);

		foreach ([['case-1', (string)$other['id']], ['case-1', (string)$planGoal['id']], ['', (string)$other['id']], ['case-1', 'no-such-goal']] as [$caseId, $goalId]) {
			try {
				$this->goals->closeOfCase(caseId: $caseId, goalId: $goalId, state: 'met', observation: 'Gezien');
				self::fail('closed ' . $goalId . ' through ' . $caseId);
			} catch (RefusedException $e) {
				self::assertSame('goal-not-found', $e->getRule());
			}
		}

		foreach ($this->objects->rowsOf('casePlanGoal') as $row) {
			self::assertSame('open', $row['state']);
		}
	}//end testAGoalOfAnotherOwnerIsNotFoundThroughTheCase()

	/**
	 * Closing through the case keeps close()'s rules: a closing state and an observation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testClosingThroughTheCaseKeepsTheCloseRules(): void {
		$saved = $this->goals->save(goal: ['case' => 'case-1', 'title' => 'Iets', 'metWhen' => 'Het besluit noemt de reden']);

		foreach ([['open', 'Gezien', 'goal-close-needs-a-closing-state'], ['met', '  ', 'goal-close-needs-an-observation']] as [$state, $observation, $rule]) {
			try {
				$this->goals->closeOfCase(caseId: 'case-1', goalId: (string)$saved['id'], state: $state, observation: $observation);
				self::fail('closed as ' . $state);
			} catch (RefusedException $e) {
				self::assertSame($rule, $e->getRule());
			}
		}
	}//end testClosingThroughTheCaseKeepsTheCloseRules()
}//end class
