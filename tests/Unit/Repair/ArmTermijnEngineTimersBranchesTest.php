<?php

/**
 * ArmTermijnEngineTimers unit tests: the skipped runs, a failed arm, the stored
 * definition lookup and carrying a pause onto the new timer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use DateTimeImmutable;
use OCA\Dossiq\Repair\ArmTermijnEngineTimers;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\ArmTermijnEngineTimers
 */
class ArmTermijnEngineTimersBranchesTest extends TestCase {

	/**
	 * The settings double.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The timer service double.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timers;

	/**
	 * The term service double.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The info and warning lines the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->timers = $this->createMock(TermijnTimerService::class);
		$this->terms = $this->createMock(TermijnService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->messages = [];
	}//end setUp()

	/**
	 * Without OpenRegister the step only warns.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsArmed(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->timers->expects(self::never())->method('armBeslistermijn');

		$this->step()->run($this->recordingOutput());

		self::assertSame(['warning: OpenRegister is not available. Skipping termijn timer migration.'], $this->messages);
	}//end testWithoutOpenRegisterNothingIsArmed()

	/**
	 * An unconfigured schema, and a refused listing, both skip the run with a warning; the refusal is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableListingSkipsTheRun(): void {
		$this->configure(store: new FakeTermijnStore(), keys: ['register' => 'dossiq']);
		$this->step()->run($this->recordingOutput());

		$refusing = new class extends FakeTermijnStore {
			/**
			 * Refuse every search.
			 *
			 * @param string               $registerSlug The register.
			 * @param string               $schemaSlug   The schema.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				throw new RuntimeException('read refused');
			}
		};
		$this->settings = $this->createMock(SettingsService::class);
		$this->configure(store: $refusing);
		$this->logger->expects(self::once())->method('error')
			->with('Dossiq termijn timer migration: listing failed', ['error' => 'read refused']);
		$this->timers->expects(self::never())->method('armBeslistermijn');

		$this->step()->run($this->recordingOutput());

		$warning = 'warning: Termijn instances not readable (schemas unconfigured, or the read was refused). Skipping timer migration.';
		self::assertSame([$warning, $warning], $this->messages);
	}//end testAnUnreadableListingSkipsTheRun()

	/**
	 * An instance the engine will not arm is counted failed and logged, and never written.
	 *
	 * @return void
	 */
	public function testAnInstanceTheEngineWillNotArmIsCountedFailed(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', ['id' => 'i1', 'status' => 'lopend']);
		$this->configure(store: $store);
		$this->timers->method('armBeslistermijn')->willReturn(null);
		$this->terms->expects(self::never())->method('updateTermijnInstance');
		$this->logger->expects(self::once())->method('warning')
			->with('Dossiq termijn timer migration: arming failed', ['instance' => 'i1']);

		$this->step()->run($this->recordingOutput());

		self::assertSame(['info: Termijn timer migration: 0 armed (0 suspended), 0 skipped, 1 failed.'], $this->messages);
	}//end testAnInstanceTheEngineWillNotArmIsCountedFailed()

	/**
	 * The stored definition is armed with; a lookup that throws or an unconfigured definition schema arms with none.
	 *
	 * @return void
	 */
	public function testTheDefinitionIsReadWhenItCanBe(): void {
		$store = new class extends FakeTermijnStore {
			/**
			 * Throw for the one broken definition id, else behave as the fake.
			 *
			 * @param int|string           $id       The id.
			 * @param array<int, mixed>|null $_extend Ignored.
			 * @param bool                 $files    Ignored.
			 * @param string|int|null      $register The register.
			 * @param string|int|null      $schema   The schema.
			 *
			 * @return \OCA\Dossiq\Tests\Unit\Service\FakeStoredObject
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): \OCA\Dossiq\Tests\Unit\Service\FakeStoredObject {
				if ((string)$id === 'broken') {
					throw new RuntimeException('lookup refused');
				}

				return parent::find($id, $_extend, $files, $register, $schema);
			}
		};
		$definition = $store->seed('deadlineDefinition', ['name' => 'Beslistermijn', 'days' => 56]);
		$store->seed('deadlineInstance', ['id' => 'withdef', 'status' => 'lopend', 'deadlineDefinition' => $definition['id']]);
		$store->seed('deadlineInstance', ['id' => 'brokendef', 'status' => 'lopend', 'deadlineDefinition' => 'broken']);
		$this->configure(store: $store, keys: ['register' => 'dossiq', 'termijn_instance_schema' => 'deadlineInstance', 'termijn_definitie_schema' => 'deadlineDefinition']);
		$passed = [];
		$this->timers->method('armBeslistermijn')->willReturnCallback(
			static function (array $instance, array $definitie) use (&$passed): string {
				$passed[$instance['id']] = $definitie['name'] ?? null;
				return 'timer-' . $instance['id'];
			}
		);
		$this->logger->expects(self::once())->method('debug')
			->with('Dossiq termijn timer migration: definition lookup failed', ['id' => 'broken']);

		$this->step()->run($this->recordingOutput());

		self::assertSame(['withdef' => 'Beslistermijn', 'brokendef' => null], $passed);
		self::assertSame(['info: Termijn timer migration: 2 armed (0 suspended), 0 skipped, 0 failed.'], $this->messages);
	}//end testTheDefinitionIsReadWhenItCanBe()

	/**
	 * Without a definition schema the definition is not looked up at all.
	 *
	 * @return void
	 */
	public function testWithoutADefinitionSchemaNoDefinitionIsRead(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', ['id' => 'i1', 'status' => 'lopend', 'deadlineDefinition' => 'def-1']);
		$this->configure(store: $store);
		$this->timers->expects(self::once())->method('armBeslistermijn')
			->with(self::anything(), [])
			->willReturn('timer-1');

		$this->step()->run($this->recordingOutput());

		self::assertSame(['info: Termijn timer migration: 1 armed (0 suspended), 0 skipped, 0 failed.'], $this->messages);
	}//end testWithoutADefinitionSchemaNoDefinitionIsRead()

	/**
	 * A paused instance is suspended on its new timer until its pause deadline; an unreadable deadline suspends open-ended.
	 *
	 * @return void
	 */
	public function testAPausedInstanceIsSuspendedOnItsNewTimer(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', ['id' => 'until', 'status' => 'paused', 'pauseDeadline' => '2026-12-01']);
		$store->seed('deadlineInstance', ['id' => 'garbled', 'status' => 'paused', 'pauseDeadline' => 'not a date']);
		$this->configure(store: $store);
		$this->timers->method('armBeslistermijn')->willReturnCallback(
			static fn (array $instance): string => 'timer-' . $instance['id']
		);
		$suspended = [];
		$this->timers->method('suspendBeslistermijn')->willReturnCallback(
			static function (array $instance, string $reason, ?DateTimeImmutable $until) use (&$suspended): bool {
				$suspended[] = [$instance['engineTimerId'], $until?->format('Y-m-d')];
				return true;
			}
		);

		$this->step()->run($this->recordingOutput());

		self::assertSame([['timer-until', '2026-12-01'], ['timer-garbled', null]], $suspended);
		self::assertSame(['info: Termijn timer migration: 2 armed (2 suspended), 0 skipped, 0 failed.'], $this->messages);
	}//end testAPausedInstanceIsSuspendedOnItsNewTimer()

	/**
	 * Point the settings double at a store and config keys.
	 *
	 * @param FakeTermijnStore      $store The object store.
	 * @param array<string, string> $keys  The config values.
	 *
	 * @return void
	 */
	private function configure(FakeTermijnStore $store, array $keys = ['register' => 'dossiq', 'termijn_instance_schema' => 'deadlineInstance']): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($keys[$key] ?? '')
		);
	}//end configure()

	/**
	 * The step over the doubles.
	 *
	 * @return ArmTermijnEngineTimers
	 */
	private function step(): ArmTermijnEngineTimers {
		return new ArmTermijnEngineTimers(
			settingsService: $this->settings,
			termService: $this->terms,
			timerService: $this->timers,
			logger: $this->logger,
		);
	}//end step()

	/**
	 * An output double that records info and warning lines.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = 'warning: ' . $message;
			}
		);

		return $output;
	}//end recordingOutput()
}//end class
