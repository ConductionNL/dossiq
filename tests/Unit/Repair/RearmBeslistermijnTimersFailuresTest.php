<?php

/**
 * RearmBeslistermijnTimers unit tests: the paths that skip the re-arm or count a term as failed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RearmBeslistermijnTimers;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\RearmBeslistermijnTimers
 * @uses   \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class RearmBeslistermijnTimersFailuresTest extends TestCase {

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
	public function testWithoutOpenRegisterNothingIsTouched(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->settings->expects(self::never())->method('getObjectService');
		$this->timers->expects(self::never())->method('cancelForInstance');

		$this->step()->run($this->recordingOutput());

		self::assertSame(['warning: OpenRegister is not available. Skipping the term timer re-arm.'], $this->messages);
	}//end testWithoutOpenRegisterNothingIsTouched()

	/**
	 * An unconfigured instance schema skips the run with a warning.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaSkipsTheRun(): void {
		$this->configure(store: new FakeTermijnStore(), keys: ['register' => 'dossiq']);
		$this->timers->expects(self::never())->method('cancelForInstance');

		$this->step()->run($this->recordingOutput());

		self::assertSame(['warning: Term instances not readable (schemas unconfigured, or the read was refused). Skipping the term timer re-arm.'], $this->messages);
	}//end testAnUnconfiguredSchemaSkipsTheRun()

	/**
	 * A refused listing is logged as an error and skips the run.
	 *
	 * @return void
	 */
	public function testARefusedListingIsLoggedAndSkipsTheRun(): void {
		$store = new class extends FakeTermijnStore {
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
		$this->configure(store: $store);
		$this->logger->expects(self::once())->method('error')
			->with('Dossiq term timer re-arm: listing the terms failed', ['error' => 'read refused']);

		$this->step()->run($this->recordingOutput());

		self::assertSame(['warning: Term instances not readable (schemas unconfigured, or the read was refused). Skipping the term timer re-arm.'], $this->messages);
	}//end testARefusedListingIsLoggedAndSkipsTheRun()

	/**
	 * A term whose arming returns no timer, and one whose cancel throws, are each counted failed and logged.
	 *
	 * @return void
	 */
	public function testATermThatCannotBeArmedIsCountedFailed(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', ['id' => 'noarm', 'case' => 'c1', 'status' => 'lopend', 'engineTimerId' => 't1']);
		$store->seed('deadlineInstance', ['id' => 'boom', 'case' => 'c2', 'status' => 'lopend', 'engineTimerId' => 't2']);
		$this->configure(store: $store);
		$this->timers->method('cancelForInstance')->willReturnCallback(
			static function (string $instanceId): int {
				if ($instanceId === 'boom') {
					throw new RuntimeException('engine down');
				}

				return 1;
			}
		);
		$this->timers->method('armBeslistermijn')->willReturn(null);
		$this->terms->expects(self::never())->method('updateTermijnInstance');
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->step()->run($this->recordingOutput());

		self::assertSame(['info: Decision term timers: 0 re-armed, 0 already right, 2 failed.'], $this->messages);
		self::assertSame(
			[
				['Dossiq term timer re-arm: arming failed', ['instance' => 'noarm']],
				['Dossiq term timer re-arm: an instance could not be re-armed', ['instance' => 'boom', 'error' => 'engine down']],
			],
			$warnings
		);
	}//end testATermThatCannotBeArmedIsCountedFailed()

	/**
	 * A term with a deadline definition is re-armed with that stored definition.
	 *
	 * @return void
	 */
	public function testTheStoredDefinitionIsPassedToTheTimer(): void {
		$store = new FakeTermijnStore();
		$definition = $store->seed('deadlineDefinition', ['name' => 'Beslistermijn', 'days' => 56]);
		$store->seed('deadlineInstance', ['id' => 'run', 'case' => 'c1', 'status' => 'lopend', 'engineTimerId' => 't1', 'deadlineDefinition' => $definition['id']]);
		$this->configure(store: $store, keys: ['register' => 'dossiq', 'termijn_instance_schema' => 'deadlineInstance', 'termijn_definitie_schema' => 'deadlineDefinition']);
		$this->timers->expects(self::once())->method('armBeslistermijn')
			->with(
				self::callback(static fn (array $instance): bool => $instance['id'] === 'run'),
				self::callback(static fn (array $passed): bool => ($passed['name'] ?? null) === 'Beslistermijn' && ($passed['days'] ?? null) === 56)
			)
			->willReturn('t-new');
		$this->terms->expects(self::once())->method('updateTermijnInstance')
			->with('run', ['engineTimerId' => 't-new', 'timerBreachesAfterLastDay' => true]);

		$this->step()->run($this->recordingOutput());

		self::assertSame(['info: Decision term timers: 1 re-armed, 0 already right, 0 failed.'], $this->messages);
	}//end testTheStoredDefinitionIsPassedToTheTimer()

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
	 * @return RearmBeslistermijnTimers
	 */
	private function step(): RearmBeslistermijnTimers {
		return new RearmBeslistermijnTimers(
			settingsService: $this->settings,
			termService: $this->terms,
			timerService: $this->timers,
			mirror: new CaseDeadlineMirror(
				settingsService: $this->settings,
				store: new TermInstanceStore(settingsService: $this->settings, logger: $this->logger),
				logger: $this->logger,
			),
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
