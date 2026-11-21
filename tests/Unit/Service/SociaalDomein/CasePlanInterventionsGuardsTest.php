<?php

/**
 * Case Plan Interventions Guards Test
 *
 * Pins the guards of the family plan interventions: a blank plan id lists
 * nothing without reading the register, an intervention without a plan or a
 * title is refused before anything is written, overdue is judged against
 * today when no day is given, and an unknown state is stored as planned.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\SociaalDomein
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\InterventionProvider;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Service\Support\ObjectSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guard tests for CasePlanInterventions.
 */
#[CoversClass(CasePlanInterventions::class)]
#[UsesClass(SociaalDomeinStore::class)]
#[UsesClass(InterventionProvider::class)]
#[UsesClass(RefusedException::class)]
#[UsesClass(ObjectSearch::class)]
final class CasePlanInterventionsGuardsTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The service over the in-memory register, without a contact service.
	 *
	 * @return CasePlanInterventions
	 */
	private function interventions(): CasePlanInterventions {
		$this->objects ??= new FakeSociaalDomeinObjects();
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new CasePlanInterventions(
			store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger()),
			provider: new InterventionProvider(settingsService: $settings, logger: new NullLogger()),
		);
	}//end interventions()

	/**
	 * A blank plan id lists nothing and never reaches the register.
	 *
	 * @return void
	 */
	public function testABlankPlanListsNothing(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->broken = true;

		self::assertSame(expected: [], actual: $this->interventions()->ofPlan(planId: '  '));
		self::assertSame(expected: [], actual: $this->objects->scopes);
	}//end testABlankPlanListsNothing()

	/**
	 * Without a day given, overdue is judged against today.
	 *
	 * @return void
	 */
	public function testOverdueDefaultsToToday(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed(schema: 'intervention', row: ['id' => 'past', 'plan' => 'plan-1', 'targetDate' => '2000-01-01', 'state' => 'running']);
		$this->objects->seed(schema: 'intervention', row: ['id' => 'future', 'plan' => 'plan-1', 'targetDate' => '2999-01-01']);
		$this->objects->seed(schema: 'intervention', row: ['id' => 'done', 'plan' => 'plan-1', 'targetDate' => '2000-01-01', 'state' => 'complete']);

		$rows = array_column($this->interventions()->ofPlan(planId: 'plan-1'), null, 'id');

		self::assertTrue(condition: $rows['past']['overdue']);
		self::assertFalse(condition: $rows['future']['overdue']);
		self::assertFalse(condition: $rows['done']['overdue']);
		self::assertNull(actual: $rows['past']['providerParty']);
	}//end testOverdueDefaultsToToday()

	/**
	 * An intervention without a plan is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnInterventionNeedsAPlan(): void {
		$this->objects = new FakeSociaalDomeinObjects();

		try {
			$this->interventions()->save(intervention: ['plan' => ' ', 'title' => 'Opvoedondersteuning']);
			self::fail(message: 'An intervention without a plan was saved.');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'intervention-needs-a-plan', actual: $e->getRule());
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
		}

		self::assertSame(expected: [], actual: $this->objects->store);
	}//end testAnInterventionNeedsAPlan()

	/**
	 * An intervention without a title is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnInterventionNeedsATitle(): void {
		$this->objects = new FakeSociaalDomeinObjects();

		try {
			$this->interventions()->save(intervention: ['plan' => 'plan-1', 'title' => '']);
			self::fail(message: 'An intervention without a title was saved.');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'intervention-needs-a-title', actual: $e->getRule());
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
		}

		self::assertSame(expected: [], actual: $this->objects->store);
	}//end testAnInterventionNeedsATitle()

	/**
	 * An unknown state is stored as planned, a known one is kept.
	 *
	 * @return void
	 */
	public function testAnUnknownStateIsStoredAsPlanned(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$service = $this->interventions();

		$unknown = $service->save(
			intervention: ['plan' => 'plan-1', 'title' => 'Budgetcoaching', 'provider' => 'user:buurtteam-zuid', 'state' => 'paused']
		);
		$stopped = $service->save(
			intervention: ['plan' => 'plan-1', 'title' => 'Huiswerkbegeleiding', 'provider' => 'user:buurtteam-zuid', 'state' => 'stopped']
		);

		self::assertSame(expected: 'planned', actual: $unknown['state']);
		self::assertSame(expected: '', actual: $unknown['migratedFrom']);
		self::assertSame(expected: 'stopped', actual: $stopped['state']);
	}//end testAnUnknownStateIsStoredAsPlanned()
}//end class
