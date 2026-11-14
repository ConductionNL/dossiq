<?php

/**
 * Edge-case unit tests for the DSO deadline acts and timer.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
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

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use DateTimeInterface;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use OCA\OpenRegister\Db\FlowTimer;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Nothing is read or written without configuration, a notification that
 * cannot be sent is skipped, and an engine that refuses to arm leaves the
 * timer unavailable.
 *
 * @covers \OCA\Dossiq\Service\Dso\DsoDeadlineActs
 * @covers \OCA\Dossiq\Service\Dso\DsoDeadlineTimer
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 */
class DsoDeadlineEdgesTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The notification manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager $manager;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('createNotification')->willReturn($this->createMock(INotification::class));
	}//end setUp()

	/**
	 * Without a register, a case schema or an id, no case is read.
	 *
	 * @return void
	 */
	public function testFindReadsNothingWithoutConfiguration(): void {
		$objects = new class {
			/**
			 * Every id asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Find.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array|null
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				$this->asked[] = $id;
				return ['id' => $id];
			}
		};

		$this->assertNull($this->acts(objects: $objects, config: ['register' => 'dossiq'])->find('case-1'));
		$this->assertNull($this->acts(objects: $objects, config: ['case_schema' => 'case'])->find('case-1'));
		$this->assertNull($this->acts(objects: $objects)->find(''));
		$this->assertNull($this->acts(objects: null)->find('case-1'));
		$this->assertSame([], $objects->asked);
	}//end testFindReadsNothingWithoutConfiguration()

	/**
	 * A notification that cannot be sent is logged and the case skipped.
	 *
	 * @return void
	 */
	public function testAFailedNotificationIsSkipped(): void {
		$this->manager->method('notify')->willThrowException(new RuntimeException('push down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq DSO: the deadline notification could not be sent: push down',
			['case' => 'case-1', 'subject' => 'dso_deadline_warning']
		);

		$result = $this->acts(objects: null)->notify($this->openCase(), 'dso_deadline_warning');

		$this->assertSame(DsoDeadlineActs::SKIPPED, $result);
	}//end testAFailedNotificationIsSkipped()

	/**
	 * A closed case, or one without an id or assignee, is never notified.
	 *
	 * @return void
	 */
	public function testOnlyAnOpenAssignedCaseIsNotified(): void {
		$this->manager->expects($this->never())->method('notify');
		$acts = $this->acts(objects: null);

		$this->assertSame(DsoDeadlineActs::SKIPPED, $acts->notify(['dsoStatus' => 'decided'] + $this->openCase(), 'dso_deadline_warning'));
		$this->assertSame(DsoDeadlineActs::SKIPPED, $acts->notify(['assignee' => ''] + $this->openCase(), 'dso_deadline_warning'));
		$this->assertSame(DsoDeadlineActs::SKIPPED, $acts->notify(['id' => ''] + $this->openCase(), 'dso_deadline_warning'));
	}//end testOnlyAnOpenAssignedCaseIsNotified()

	/**
	 * Without configuration an overdue case is notified but not marked.
	 *
	 * @return void
	 */
	public function testAnOverdueCaseIsNotMarkedWithoutConfiguration(): void {
		$this->manager->expects($this->once())->method('notify');

		$this->assertFalse($this->acts(objects: null)->markOverdue($this->openCase()));
	}//end testAnOverdueCaseIsNotMarkedWithoutConfiguration()

	/**
	 * An engine that refuses to arm leaves the term unavailable, and says why.
	 *
	 * @return void
	 */
	public function testAnEngineRefusingToArmLeavesTheTermUnavailable(): void {
		$engine = new class extends FlowTimerEngineFake {
			/**
			 * Refuse to arm.
			 *
			 * @param array<string, mixed>   $config The timer configuration.
			 * @param string|null            $actor  The actor.
			 * @param DateTimeInterface|null $now    The clock.
			 *
			 * @return FlowTimer
			 */
			public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
				throw new RuntimeException('quota reached');
			}
		};
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq DSO: the engine refused the DSO term timer', ['case' => 'case-1', 'error' => 'quota reached']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(DsoDeadlineTimer::ENGINE_CLASS)->willReturn($engine);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $default);
		$timer = new DsoDeadlineTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->logger,
		);

		$result = $timer->sync(['id' => 'case-1', 'dsoStatus' => 'in_handling', 'deadlineDate' => '2026-12-01']);

		$this->assertSame(DsoDeadlineTimer::UNAVAILABLE, $result);
		$this->assertCount(1, $engine->calls['cancelForSubject']);
	}//end testAnEngineRefusingToArmLeavesTheTermUnavailable()

	/**
	 * An open, assigned case.
	 *
	 * @return array<string, string>
	 */
	private function openCase(): array {
		return ['id' => 'case-1', 'assignee' => 'alice', 'dsoStatus' => 'submitted'];
	}//end openCase()

	/**
	 * The acts over the given object service and configuration.
	 *
	 * @param object|null           $objects The object service, or null for none.
	 * @param array<string, string> $config  The configuration.
	 *
	 * @return DsoDeadlineActs
	 */
	private function acts(?object $objects, array $config = ['register' => 'dossiq', 'case_schema' => 'case']): DsoDeadlineActs {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new DsoDeadlineActs(
			settingsService: $settings,
			notifications: $this->manager,
			journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)),
			logger: $this->logger,
		);
	}//end acts()
}//end class
