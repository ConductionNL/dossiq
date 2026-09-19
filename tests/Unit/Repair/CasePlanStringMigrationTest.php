<?php

/**
 * Unit tests for MigrateCasePlanStrings.
 *
 * 🔴 EVERY STRING IN THOSE ARRAYS IS TEXT SOMEBODY WROTE ABOUT A REAL
 * HOUSEHOLD. `goals` and `deploymentTrajectories` were plain text, and the
 * shapes replacing them ask for a provider, a target date and what would count
 * as met, none of which the old shape ever held. So the two properties tested
 * here pull in opposite directions and both matter: NOTHING IS DROPPED, and
 * NOTHING IS INVENTED. A migration that filled in a plausible provider would
 * put a commitment nobody made into a plan a family is worked to, and a
 * migration that skipped the rows it could not complete would lose the only
 * record of what was agreed.
 *
 * 🔑 THE IDEMPOTENCE IS PER PLAN, NOT PER STRING. A second run that matched on
 * text would re-import a goal whose wording a consulent has since corrected,
 * because the corrected text no longer matches, and the household would end up
 * with both. The test for that edits a migrated goal and runs again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateCasePlanStrings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Tests\Unit\Service\SociaalDomein\FakeSociaalDomeinObjects;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the plan-string migration.
 *
 * @covers \OCA\Dossiq\Repair\MigrateCasePlanStrings
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Service\SettingsService
 */
class CasePlanStringMigrationTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The repair step under test.
	 *
	 * @var MigrateCasePlanStrings
	 */
	private MigrateCasePlanStrings $migration;

	/**
	 * The four goal strings the fixture carries.
	 *
	 * @var array<int, string>
	 */
	private const GOALS = [
		'Sem gaat weer naar school',
		'Rust in huis',
		'Moeder heeft eigen inkomen',
		'Vader en moeder praten weer met elkaar over de kinderen',
	];

	/**
	 * The two trajectory strings the fixture carries.
	 *
	 * @var array<int, string>
	 */
	private const TRAJECTORIES = [
		'Ambulante begeleiding via Jeugdzorg Midden',
		'Schuldhulpverlening',
	];

	/**
	 * One plan with four goal strings and two trajectory strings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed(
			'gezinsplan',
			[
				'id' => 'plan-1',
				'caseId' => 'case-1',
				'preparedBy' => 'anna',
				'goals' => self::GOALS,
				'deploymentTrajectories' => self::TRAJECTORIES,
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);

		// The SAME settings double the store reads, so the step elevates over
		// the very object service the store then writes through. Two doubles
		// would let a step that elevated over nothing pass.
		$this->migration = new MigrateCasePlanStrings(
			store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger()),
			settingsService: $settings,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * An output sink that records nothing.
	 *
	 * @return IOutput The mock.
	 */
	private function sink(): IOutput {
		return $this->createMock(IOutput::class);
	}//end sink()

	/**
	 * 🔴 Six records exist, carrying those exact texts.
	 *
	 * @return void
	 */
	public function testEveryStringSurvivesTheMigration(): void {
		$this->migration->run($this->sink());

		$goals = $this->objects->rowsOf('casePlanGoal');
		$interventions = $this->objects->rowsOf('intervention');

		self::assertCount(4, $goals);
		self::assertCount(2, $interventions);
		self::assertSame(self::GOALS, array_column($goals, 'title'));
		self::assertSame(self::TRAJECTORIES, array_column($interventions, 'title'));
	}//end testEveryStringSurvivesTheMigration()

	/**
	 * 🔴 Each record is marked as migrated from the shape it came out of.
	 *
	 * @return void
	 */
	public function testEachRecordIsMarkedWithWhereItCameFrom(): void {
		$this->migration->run($this->sink());

		foreach ($this->objects->rowsOf('casePlanGoal') as $goal) {
			self::assertSame(MigrateCasePlanStrings::FROM_GOALS, $goal['migratedFrom']);
		}

		foreach ($this->objects->rowsOf('intervention') as $intervention) {
			self::assertSame(MigrateCasePlanStrings::FROM_TRAJECTORIES, $intervention['migratedFrom']);
		}
	}//end testEachRecordIsMarkedWithWhereItCameFrom()

	/**
	 * 🔴 No provider and no target date is invented.
	 *
	 * The old shape held neither, and a plausible-looking one in a plan a
	 * family is worked to is a commitment nobody made.
	 *
	 * @return void
	 */
	public function testNoProviderOrTargetDateIsInvented(): void {
		$this->migration->run($this->sink());

		foreach ($this->objects->rowsOf('intervention') as $intervention) {
			self::assertSame('', $intervention['provider'], 'no provider was made up');
			self::assertArrayNotHasKey('targetDate', $intervention, 'no target date was made up');
			self::assertArrayNotHasKey('startDate', $intervention, 'no start date was made up');
			self::assertSame('planned', $intervention['state']);
		}

		foreach ($this->objects->rowsOf('casePlanGoal') as $goal) {
			self::assertArrayNotHasKey('closedDate', $goal);
			self::assertSame('open', $goal['state']);
			// `metWhen` is required by the schema and the old shape cannot
			// supply one, so the text stands in for itself. Repeating it
			// neither drops the goal nor makes something up, and the migration
			// mark says why it reads that way.
			self::assertSame($goal['title'], $goal['metWhen']);
		}
	}//end testNoProviderOrTargetDateIsInvented()

	/**
	 * 🔴 A second run writes nothing, even after a text was corrected.
	 *
	 * @return void
	 */
	public function testASecondRunDoesNotDuplicateACorrectedGoal(): void {
		$this->migration->run($this->sink());

		// A consulent rewords one of the migrated goals, which is the whole
		// point of migrating them into something editable.
		$firstGoalId = (string)$this->objects->rowsOf('casePlanGoal')[0]['id'];
		$this->objects->store['casePlanGoal'][$firstGoalId]['title'] = 'Sem gaat vier dagen per week naar school';

		$this->migration->run($this->sink());

		self::assertCount(4, $this->objects->rowsOf('casePlanGoal'), 'no goal came back a second time');
		self::assertCount(2, $this->objects->rowsOf('intervention'));
	}//end testASecondRunDoesNotDuplicateACorrectedGoal()

	/**
	 * A plan with no strings on it produces nothing, and does not fail.
	 *
	 * @return void
	 */
	public function testAPlanWithNoStringsProducesNothing(): void {
		$this->objects->seed('gezinsplan', ['id' => 'plan-2', 'caseId' => 'case-2', 'preparedBy' => 'bram']);

		$this->migration->run($this->sink());

		foreach ($this->objects->rowsOf('casePlanGoal') as $goal) {
			self::assertSame('plan-1', $goal['plan'], 'nothing was attached to the empty plan');
		}
	}//end testAPlanWithNoStringsProducesNothing()

	/**
	 * An empty string in the array is not a goal, and is not carried.
	 *
	 * @return void
	 */
	public function testBlankStringsAreNotCarried(): void {
		$this->objects->store['gezinsplan']['plan-1']['goals'] = ['Rust in huis', '', '   '];

		$this->migration->run($this->sink());

		self::assertCount(1, $this->objects->rowsOf('casePlanGoal'));
	}//end testBlankStringsAreNotCarried()

	/**
	 * 🔴 An unreadable store writes NOTHING and says so, rather than reporting zero.
	 *
	 * A repair step that cannot read is not a repair step that found nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreLeavesTheDataAlone(): void {
		$this->objects->broken = true;

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->migration->run($output);

		self::assertSame([], $this->objects->rowsOf('casePlanGoal'));
	}//end testAnUnreadableStoreLeavesTheDataAlone()
}//end class
