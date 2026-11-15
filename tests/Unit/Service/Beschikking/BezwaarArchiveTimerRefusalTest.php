<?php

/**
 * Unit tests for BezwaarArchiveTimer when the engine takes the cancel and then
 * refuses to arm: the refusal is logged with the trigger and answered as
 * unavailable, never thrown.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use DateTimeInterface;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use OCA\OpenRegister\Db\FlowTimer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class BezwaarArchiveTimerRefusalTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The engine takes the cancel, refuses the arm: the refusal is logged and answered as unavailable.
	 *
	 * @return void
	 */
	public function testAnEngineThatRefusesToArmIsLoggedAndAnsweredUnavailable(): void {
		$engine = new class extends FlowTimerEngineFake {
			/**
			 * Refuse every arm.
			 *
			 * @param array<string, mixed>   $config The timer configuration.
			 * @param string|null            $actor  The arming identity.
			 * @param DateTimeInterface|null $now    The clock.
			 *
			 * @return FlowTimer
			 *
			 * @throws RuntimeException Always.
			 */
			public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
				throw new RuntimeException(message: 'timer table locked');
			}//end arm()
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(BezwaarArchiveTimer::ENGINE_CLASS)->willReturn($engine);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Dossiq bezwaar: the engine refused the bezwaartermijn timer',
			['trigger' => 'trig-1', 'error' => 'timer table locked'],
		);

		$timer = new BezwaarArchiveTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $logger,
		);

		$result = $timer->sync(trigger: ['id' => 'trig-1', 'archiveDate' => '2026-11-13', 'archiveTriggerActive' => true]);

		$this->assertSame(expected: BezwaarArchiveTimer::UNAVAILABLE, actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $engine->calls['cancelForSubject']);
	}//end testAnEngineThatRefusesToArmIsLoggedAndAnsweredUnavailable()
}//end class
