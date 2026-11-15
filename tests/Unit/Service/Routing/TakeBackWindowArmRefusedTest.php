<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use OCA\OpenRegister\Db\FlowTimer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An engine that cancels the old take-back timer but refuses to arm the new one.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 */
class ArmRefusingTimerEngine extends FlowTimerEngineFake {

	/**
	 * Refuse to arm.
	 *
	 * @param array<string, mixed>   $config The timer configuration.
	 * @param string|null            $actor  The actor.
	 * @param DateTimeInterface|null $now    The clock.
	 *
	 * @return FlowTimer
	 *
	 * @throws RuntimeException Always.
	 */
	public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
		$this->calls['arm'][] = ['config' => $config, 'actor' => $actor];
		throw new RuntimeException('no calendar for businessDays');
	}//end arm()
}//end class

/**
 * A take-back timer the engine will not arm is reported unavailable and logged.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @covers \OCA\Dossiq\Service\Routing\TakeBackWindow
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackWindowArmRefusedTest extends TestCase {

	/**
	 * The old timer is cancelled, the refused arm is logged, and the answer is UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testAnEngineThatRefusesToArmIsReportedUnavailable(): void {
		$engine = new ArmRefusingTimerEngine();
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(TakeBackWindow::ENGINE_CLASS)->willReturn($engine);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq routing: the engine refused the take-back timer',
			['case' => 'case-1', 'error' => 'no calendar for businessDays'],
		);

		$outcome = (new TakeBackWindow(settingsService: $settings, logger: $logger))->arm(
			caseId: 'case-1',
			rule: ['takeBackAfter' => ['value' => 2, 'unit' => 'businessDays']],
			routedTo: 'aad',
			routedAt: new DateTimeImmutable('2026-10-12T09:00:00+02:00')
		);

		self::assertSame(expected: TakeBackWindow::UNAVAILABLE, actual: $outcome);
		self::assertSame(expected: 'case-1:take-back', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
		self::assertSame(expected: 'case-1:take-back', actual: $engine->calls['arm'][0]['config']['subjectUuid']);
		self::assertNull(actual: $engine->calls['arm'][0]['actor']);
	}//end testAnEngineThatRefusesToArmIsReportedUnavailable()
}//end class
