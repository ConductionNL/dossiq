<?php

/**
 * A maximum dwell is a timer, not a sweep.
 *
 * Entering a status with a declared maximum arms an engine timer; leaving it
 * cancels one. The cancel is unconditional, and that is the assertion worth
 * having: a move INTO a status with no maximum still has to stop the clock on
 * the status the case left, or the next breach names a status the case is no
 * longer in and a handler is sent to look at the wrong thing.
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
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\DerivedStatusService;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\Status\StatusDwellService;
use OCA\Dossiq\Service\Status\StatusDwellTimer;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusDwellTimer
 * @covers \OCA\Dossiq\Service\Status\StatusDeclarations
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusDwellTimerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The engine every call is recorded against.
	 *
	 * @var FlowTimerEngineFake
	 */
	private FlowTimerEngineFake $engine;

	/**
	 * Build the timer over the recording engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new FlowTimerEngineFake();
	}//end setUp()

	/**
	 * A timer over the recording engine.
	 *
	 * @return StatusDwellTimer
	 */
	private function timer(): StatusDwellTimer {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')
			->with(TermijnTimerService::ENGINE_CLASS)
			->willReturn($this->engine);

		return new StatusDwellTimer(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end timer()

	/**
	 * The declarations facade over a lookup that answers the given rows.
	 *
	 * @param array<string, array<string, mixed>> $statusTypes The rows, keyed by id.
	 *
	 * @return StatusDeclarations
	 */
	private function declarations(array $statusTypes): StatusDeclarations {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturnCallback(
			static fn (string $statusTypeId): array => ($statusTypes[$statusTypeId] ?? []),
		);

		$declaration = new StatusDeclaration();
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturn(array_values($statusTypes));

		return new StatusDeclarations(
			lookup: $lookup,
			declaration: $declaration,
			derived: new DerivedStatusService(
				caseTypes: $resolver,
				declaration: $declaration,
				evaluator: new DerivedStatusEvaluator(declaration: $declaration),
			),
			dwell: new StatusDwellService(
				calendar: new WorkingDayCalculator(),
				dates: $this->caseDates(),
			),
			timer: $this->timer(),
		);
	}//end declarations()

	/**
	 * The maximum is armed in the engine's own working-day unit.
	 *
	 * Not calendar days: five days entered on a Friday with a general holiday
	 * inside the window is due five WORKING days later, and the calendar that
	 * decides is the one the organisation administers rather than a second
	 * list this app keeps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheMaximumIsArmedInWorkingDaysOnTheEngineCalendar(): void {
		$uuid = $this->timer()->arm(
			caseId: 'case-1',
			statusTypeId: 'review',
			maximumDwell: 5,
			statusName: 'Under review',
		);

		self::assertSame(expected: 'timer-1', actual: $uuid);

		$config = $this->engine->calls['arm'][0]['config'];
		self::assertSame(expected: 'object', actual: $config['subjectType']);
		self::assertSame(expected: 'case-1', actual: $config['subjectUuid']);
		self::assertSame(expected: ['value' => 5, 'unit' => 'businessDays'], actual: $config['sla']);
		self::assertSame(expected: 'status_entered', actual: $config['anchorEvent']);
		self::assertSame(expected: 'dossiq-status-dwell', actual: $config['metadata']['source']);
		self::assertSame(expected: 'review', actual: $config['metadata']['statusTypeId']);
	}//end testTheMaximumIsArmedInWorkingDaysOnTheEngineCalendar()

	/**
	 * A dwell timer carries no statutory weight and no ladder.
	 *
	 * `legalEffect: none` and no `ladder` key: a service level that escalated
	 * through the beslistermijn ladder would put a status maximum in front of
	 * the people a statutory breach is for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testADwellTimerIsNotAStatutoryTerm(): void {
		$this->timer()->arm(caseId: 'case-1', statusTypeId: 'review', maximumDwell: 5);

		$config = $this->engine->calls['arm'][0]['config'];
		self::assertSame(expected: 'none', actual: $config['legalEffect']);
		self::assertArrayNotHasKey(key: 'ladder', array: $config);
		self::assertSame(
			expected: 'status-dwell-verlopen',
			actual: $config['escalationRules'][0]['message'],
		);
	}//end testADwellTimerIsNotAStatutoryTerm()

	/**
	 * Leaving the status cancels the timer, and no breach is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testLeavingTheStatusCancelsTheTimer(): void {
		$declarations = $this->declarations(statusTypes: [
			'review' => ['id' => 'review', 'name' => 'Under review', 'order' => 1, 'maximumDwell' => 20],
			'decision' => ['id' => 'decision', 'name' => 'Decision', 'order' => 2],
		]);

		$armed = $declarations->retime(caseId: 'case-1', toStatus: 'decision');

		self::assertNull(actual: $armed);
		self::assertCount(expectedCount: 1, haystack: $this->engine->calls['cancelForSubject']);
		self::assertSame(
			expected: 'case-1',
			actual: $this->engine->calls['cancelForSubject'][0]['subjectUuid'],
		);
		self::assertArrayNotHasKey(key: 'arm', array: $this->engine->calls);
	}//end testLeavingTheStatusCancelsTheTimer()

	/**
	 * Entering a status with a maximum cancels the old clock and arms a new one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testEnteringAStatusWithAMaximumArmsAfterCancelling(): void {
		$declarations = $this->declarations(statusTypes: [
			'review' => ['id' => 'review', 'name' => 'Under review', 'order' => 1, 'maximumDwell' => 20],
		]);

		$armed = $declarations->retime(caseId: 'case-1', toStatus: 'review');

		self::assertSame(expected: 'timer-1', actual: $armed);
		self::assertCount(expectedCount: 1, haystack: $this->engine->calls['cancelForSubject']);
		self::assertSame(expected: 20, actual: $this->engine->calls['arm'][0]['config']['sla']['value']);
	}//end testEnteringAStatusWithAMaximumArmsAfterCancelling()

	/**
	 * An engine that is absent or refusing does not hold the case.
	 *
	 * A case that could not move because a service-level clock could not be
	 * armed would be a case held hostage by a service level. The move
	 * proceeds; the log line is the operator's signal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testARefusingEngineDoesNotHoldTheCase(): void {
		$this->engine->refuse = true;

		self::assertNull(
			actual: $this->timer()->arm(caseId: 'case-1', statusTypeId: 'review', maximumDwell: 5),
		);
		self::assertSame(expected: 0, actual: $this->timer()->cancel(caseId: 'case-1'));
	}//end testARefusingEngineDoesNotHoldTheCase()
}//end class
