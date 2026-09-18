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
		self::assertCount(1, $this->goals->of(planId: 'plan-1'));
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
	 * A goal with no plan and a goal with no title are refused too.
	 *
	 * @return void
	 */
	public function testAGoalNeedsAPlanAndATitle(): void {
		try {
			$this->goals->save(goal: ['title' => 'x', 'metWhen' => 'y']);
			self::fail('A goal with no plan must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('goal-needs-a-plan', $e->getRule());
		}

		try {
			$this->goals->save(goal: ['plan' => 'plan-1', 'metWhen' => 'y']);
			self::fail('A goal with no title must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('goal-needs-a-title', $e->getRule());
		}
	}//end testAGoalNeedsAPlanAndATitle()

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
			on: '2026-02-09',
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
		$this->goals->of(planId: 'plan-1');
	}//end testAnUnreadableStoreRefusesRatherThanAnsweringEmpty()
}//end class
