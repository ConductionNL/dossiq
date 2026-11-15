<?php

/**
 * ReconcileCaseDeadlinesWithTerms unit tests (REQ-OTE-08).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ReconcileCaseDeadlinesWithTerms;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\ReconcileCaseDeadlinesWithTerms
 * @uses   \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class ReconcileCaseDeadlinesWithTermsTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The step under test.
	 *
	 * @var ReconcileCaseDeadlinesWithTerms
	 */
	private ReconcileCaseDeadlinesWithTerms $step;

	/**
	 * Wire the step over a store with three cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		$this->step = new ReconcileCaseDeadlinesWithTerms(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);

		// An extended case whose list date disagreed, a case that agrees, and a
		// case with only a planned end.
		$this->objects->seed('case', ['id' => 'extended', 'deadline' => '2026-11-02']);
		$this->objects->seed('deadlineInstance', ['id' => 't1', 'case' => 'extended', 'status' => 'verlengd', 'endDateCurrent' => '2026-11-16']);
		$this->objects->seed('case', ['id' => 'agrees', 'deadline' => '2026-10-20', CaseDeadlineMirror::FIELD => '2026-10-20']);
		$this->objects->seed('deadlineInstance', ['id' => 't2', 'case' => 'agrees', 'status' => 'lopend', 'endDateCurrent' => '2026-10-20']);
		$this->objects->seed('case', ['id' => 'planned', 'deadline' => '2026-12-01']);
		$this->objects->seed('deadlineInstance', ['id' => 't3', 'case' => 'planned', 'kind' => 'planned', 'status' => 'lopend', 'endDateCurrent' => '2026-11-01']);
	}//end setUp()

	/**
	 * The disagreeing case is written, once; the rest are left alone.
	 *
	 * @return void
	 */
	public function testACaseWhoseListDateDisagreedIsRepairedOnce(): void {
		$output = $this->createMock(IOutput::class);
		$messages = [];
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->step->run($output);

		self::assertSame('2026-11-16', $this->objects->get('case', 'extended')[CaseDeadlineMirror::FIELD]);
		self::assertArrayNotHasKey(CaseDeadlineMirror::FIELD, $this->objects->get('case', 'planned'));
		self::assertSame(['Case deadlines from their statutory terms: 1 written, 1 already right, 0 failed.'], $messages);

		// The listener would have set `deadline` inside that save; the second
		// run reads the case as the live save leaves it.
		$case = $this->objects->get('case', 'extended');
		$case['deadline'] = '2026-11-16';
		$this->objects->seed('case', $case);

		$messages = [];
		$this->step->run($output);
		self::assertSame(['Case deadlines from their statutory terms: 0 written, 2 already right, 0 failed.'], $messages);
	}//end testACaseWhoseListDateDisagreedIsRepairedOnce()

	/**
	 * Without OpenRegister the step warns and does nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);
		$step = new ReconcileCaseDeadlinesWithTerms(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('OpenRegister is not available'));
		$output->expects(self::never())->method('info');

		$step->run($output);
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * Terms that cannot be listed skip the repair with a warning, not a green count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function testAnUnreadableTermListSkipsWithAWarning(): void {
		$broken = new class extends FakeTermijnStore {
			/**
			 * @param mixed ...$args Anything.
			 *
			 * @return never
			 */
			public function findObjects(mixed ...$args): never {
				throw new \RuntimeException('database gone');
			}

			/**
			 * @param mixed ...$args Anything.
			 *
			 * @return never
			 */
			public function searchObjects(mixed ...$args): never {
				throw new \RuntimeException('database gone');
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($broken);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$step = new ReconcileCaseDeadlinesWithTerms(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not readable'));
		$output->expects(self::never())->method('info');

		$step->run($output);
	}//end testAnUnreadableTermListSkipsWithAWarning()
}//end class
