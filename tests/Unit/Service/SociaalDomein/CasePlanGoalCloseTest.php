<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * Every outcome of closing a goal: the refusals in their order, and what a close writes.
 */
class CasePlanGoalCloseTest extends TestCase {

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
	 * A plan with one open goal on it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed('gezinsplan', ['id' => 'plan-1', 'caseId' => 'case-1', 'preparedBy' => 'anna']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$this->goals = new CasePlanGoals(store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger()));
	}//end setUp()

	/**
	 * A saved open goal's id.
	 *
	 * @return string The id.
	 */
	private function goalId(): string {
		return (string)$this->goals->save(goal: ['plan' => 'plan-1', 'title' => 'x', 'metWhen' => ' y '])['id'];
	}//end goalId()

	/**
	 * The refusal close throws, as rule, status and sentence.
	 *
	 * @param string $goalId      The goal.
	 * @param string $state       The state.
	 * @param string $observation The observation.
	 *
	 * @return array{0: string, 1: int, 2: string} The refusal.
	 */
	private function refusal(string $goalId, string $state, string $observation): array {
		try {
			$this->goals->close(goalId: $goalId, state: $state, observation: $observation);
		} catch (RefusedException $e) {
			return [$e->getRule(), $e->getStatus(), $e->getSentence()];
		}

		self::fail('Not refused');
	}//end refusal()

	/**
	 * An unknown goal is refused before the state and the observation are looked at.
	 *
	 * @return void
	 */
	public function testAnUnknownGoalIsRefusedFirst(): void {
		self::assertSame(
			['goal-not-found', 422, 'That goal could not be found.'],
			$this->refusal(goalId: 'nope', state: 'open', observation: '')
		);
	}//end testAnUnknownGoalIsRefusedFirst()

	/**
	 * A state that does not close is refused before the observation is looked at.
	 *
	 * @return void
	 */
	public function testTheStateIsCheckedBeforeTheObservation(): void {
		self::assertSame(
			['goal-close-needs-a-closing-state', 422, 'A goal is closed as met, not met or withdrawn.'],
			$this->refusal(goalId: $this->goalId(), state: 'Met', observation: '')
		);
	}//end testTheStateIsCheckedBeforeTheObservation()

	/**
	 * The observation refusal quotes the trimmed metWhen; nothing is written.
	 *
	 * @return void
	 */
	public function testNoObservation(): void {
		self::assertSame(
			['goal-close-needs-an-observation', 422, 'Write what you saw, against what this goal said would count as met: "y".'],
			$this->refusal(goalId: $this->goalId(), state: 'withdrawn', observation: " \n")
		);
		self::assertSame('open', $this->objects->rowsOf('casePlanGoal')[0]['state']);
	}//end testNoObservation()

	/**
	 * A close writes the state, the trimmed observation and today when no day is given.
	 *
	 * @return void
	 */
	public function testACloseOnToday(): void {
		$closed = $this->goals->close(goalId: $this->goalId(), state: 'not-met', observation: '  gezien  ');

		self::assertSame('not-met', $closed['state']);
		self::assertSame('gezien', $closed['closedObservation']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $closed['closedDate']);
		self::assertSame($closed['closedDate'], $this->objects->rowsOf('casePlanGoal')[0]['closedDate']);
	}//end testACloseOnToday()
}//end class
