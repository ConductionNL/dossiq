<?php

/**
 * Tests for TakeBackWindow: the take-back window is an armed engine timer.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use DateTimeImmutable;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The window a rule declares, armed and cancelled on the engine.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackWindowTest extends TestCase {

	/**
	 * The window over an engine (or none).
	 *
	 * @param FlowTimerEngineFake|null $engine The engine, null when OpenRegister ships none.
	 *
	 * @return TakeBackWindow
	 */
	private function window(?FlowTimerEngineFake $engine): TakeBackWindow {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(TakeBackWindow::ENGINE_CLASS)->willReturn($engine);

		return new TakeBackWindow(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end window()

	/**
	 * A declared window arms one timer on the case's own subject, after cancelling the old one.
	 *
	 * @return void
	 */
	public function testADeclaredWindowArmsOneTimerForTheHolder(): void {
		$engine   = new FlowTimerEngineFake();
		$routedAt = new DateTimeImmutable('2026-10-12T09:00:00+02:00');

		$outcome = $this->window(engine: $engine)->arm(
			caseId: 'case-1',
			rule: ['strategy' => 'round-robin', 'roleType' => 'behandelaar', 'takeBackAfter' => ['value' => 2, 'unit' => 'businessDays']],
			routedTo: 'aad',
			routedAt: $routedAt
		);

		$this->assertSame(expected: TakeBackWindow::ARMED, actual: $outcome);
		$this->assertSame(expected: 'case-1:take-back', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
		$config = $engine->calls['arm'][0]['config'];
		$this->assertSame(expected: 'case-1:take-back', actual: $config['subjectUuid']);
		$this->assertSame(expected: ['value' => 2, 'unit' => 'businessDays'], actual: $config['sla']);
		$this->assertSame(expected: 'slaBreached', actual: $config['escalationRules'][0]['trigger']);
		$this->assertSame(expected: $routedAt, actual: $config['anchorEventAt']);
		$this->assertSame(
			expected: ['source' => TakeBackWindow::METADATA_SOURCE, 'caseId' => 'case-1', 'routedTo' => 'aad', 'routedAt' => '2026-10-12T09:00:00+02:00'],
			actual: $config['metadata']
		);
	}//end testADeclaredWindowArmsOneTimerForTheHolder()

	/**
	 * No window, a broken window or nobody to hold it arms nothing.
	 *
	 * @return void
	 */
	public function testNoOrABrokenWindowArmsNothing(): void {
		$engine = new FlowTimerEngineFake();
		$window = $this->window(engine: $engine);
		$at     = new DateTimeImmutable('2026-10-12T09:00:00+02:00');

		$this->assertSame(expected: TakeBackWindow::SKIPPED, actual: $window->arm(caseId: 'case-1', rule: ['strategy' => 'round-robin'], routedTo: 'aad', routedAt: $at));
		$this->assertSame(expected: TakeBackWindow::SKIPPED, actual: $window->arm(caseId: 'case-1', rule: ['takeBackAfter' => ['value' => 0, 'unit' => 'hours']], routedTo: 'aad', routedAt: $at));
		$this->assertSame(expected: TakeBackWindow::SKIPPED, actual: $window->arm(caseId: 'case-1', rule: ['takeBackAfter' => ['value' => 2, 'unit' => 'weeks']], routedTo: 'aad', routedAt: $at));
		$this->assertSame(expected: TakeBackWindow::SKIPPED, actual: $window->arm(caseId: 'case-1', rule: ['takeBackAfter' => ['value' => 2, 'unit' => 'hours']], routedTo: '', routedAt: $at));
		$this->assertSame(expected: [], actual: $engine->calls);

		$this->assertNull($window->windowOf(rule: ['takeBackAfter' => 'two days']));
		$this->assertSame(expected: ['value' => 8, 'unit' => 'hours'], actual: $window->windowOf(rule: ['takeBackAfter' => ['value' => '8', 'unit' => 'hours']]));
	}//end testNoOrABrokenWindowArmsNothing()

	/**
	 * Cancelling touches only the take-back subject; an absent or refusing engine says so.
	 *
	 * @return void
	 */
	public function testCancelAndAnEngineThatIsNotThere(): void {
		$engine = new FlowTimerEngineFake();
		$this->assertSame(expected: TakeBackWindow::CANCELLED, actual: $this->window(engine: $engine)->cancel(caseId: 'case-1', reason: 'Accepted'));
		$this->assertSame(expected: 'case-1:take-back', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
		$this->assertSame(expected: TakeBackWindow::SKIPPED, actual: $this->window(engine: $engine)->cancel(caseId: '', reason: 'Accepted'));

		$absent = $this->window(engine: null);
		$this->assertSame(expected: TakeBackWindow::UNAVAILABLE, actual: $absent->cancel(caseId: 'case-1', reason: 'Accepted'));
		$this->assertSame(
			expected: TakeBackWindow::UNAVAILABLE,
			actual: $absent->arm(caseId: 'case-1', rule: ['takeBackAfter' => ['value' => 1, 'unit' => 'calendarDays']], routedTo: 'aad', routedAt: new DateTimeImmutable())
		);

		$refusing         = new FlowTimerEngineFake();
		$refusing->refuse = true;
		$this->assertSame(
			expected: TakeBackWindow::UNAVAILABLE,
			actual: $this->window(engine: $refusing)->arm(caseId: 'case-1', rule: ['takeBackAfter' => ['value' => 1, 'unit' => 'calendarDays']], routedTo: 'aad', routedAt: new DateTimeImmutable())
		);
	}//end testCancelAndAnEngineThatIsNotThere()
}//end class
