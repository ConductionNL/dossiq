<?php

/**
 * Dossiq FirstResponseOutcome store failure test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\FirstResponseOutcome;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The first-response outcome when the store is missing, unconfigured or failing.
 *
 * @covers \OCA\Dossiq\Service\Term\FirstResponseOutcome
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
final class FirstResponseOutcomeStoreTest extends TestCase {
	/**
	 * The log lines, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * The store calls made, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * An outcome over a store that answers rows or throws.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $rows      The stored cases, or the error the store throws.
	 * @param array<string, string>                            $config    Config overrides.
	 * @param bool                                             $noObjects Whether OpenRegister is absent.
	 * @param WorkingDayCalculator|null                        $workDays  The working-day calculator.
	 *
	 * @return FirstResponseOutcome
	 */
	private function outcome(
		array|RuntimeException $rows = [],
		array $config = [],
		bool $noObjects = false,
		?WorkingDayCalculator $workDays = null,
	): FirstResponseOutcome {
		$config += ['register' => 'dossiq', 'case_schema' => 'case'];
		$test = $this;
		$store = new class ($test, $rows) {
			/**
			 * Constructor.
			 *
			 * @param FirstResponseOutcomeStoreTest                      $test The test.
			 * @param array<int, array<string, mixed>>|RuntimeException $rows The answer.
			 */
			public function __construct(private FirstResponseOutcomeStoreTest $test, private array|RuntimeException $rows) {
			}

			/**
			 * The stored cases, or the error.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->calls[] = ['search', ['register' => $register, 'schema' => $schema, 'filters' => $filters]];
				if ($this->rows instanceof RuntimeException) {
					throw $this->rows;
				}

				return $this->rows;
			}

			/**
			 * Write fields onto a case, or fail.
			 *
			 * @param string          $objectId The case.
			 * @param array           $data     The fields.
			 * @param int|string|null $register The register.
			 * @param int|string|null $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, int|string|null $register = null, int|string|null $schema = null): array {
				$this->test->calls[] = ['patch', ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]];
				if ($this->rows instanceof RuntimeException) {
					throw $this->rows;
				}

				return $data;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noObjects ? null : $store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? '');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logged[] = [$level, $message];
			});
		}

		return new FirstResponseOutcome(
			settingsService: $settings,
			workingDays: ($workDays ?? $this->createMock(WorkingDayCalculator::class)),
			logger: $logger
		);
	}//end outcome()

	/**
	 * A failing write still answers the outcome, and logs the case id at error level.
	 *
	 * @return void
	 */
	public function testAFailedWriteStillAnswersAndLogs(): void {
		$result = $this->outcome(new RuntimeException('locked'))->record(
			'case-9',
			new DateTimeImmutable('2026-09-09T17:00:00+00:00'),
			new DateTimeImmutable('2026-09-09T12:00:00+00:00')
		);

		$this->assertSame(['status' => 'met', 'overrunDays' => 0], $result);
		$this->assertSame([['error', 'Dossiq: the first-response outcome of "case-9" was not recorded: locked']], $this->logged);
	}//end testAFailedWriteStillAnswersAndLogs()

	/**
	 * The write goes to the configured register and schema, with the send moment in ISO form.
	 *
	 * @return void
	 */
	public function testTheWriteCarriesTheFields(): void {
		$this->outcome()->record('case-1', new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-08T09:30:00+02:00'));

		$this->assertSame(
			['patch', [
				'id' => 'case-1',
				'data' => ['firstResponseStatus' => 'met', 'firstResponseOverrunDays' => 0, 'firstResponseAt' => '2026-09-08T09:30:00+02:00'],
				'register' => 'dossiq',
				'schema' => 'case',
			]],
			$this->calls[0]
		);
	}//end testTheWriteCarriesTheFields()

	/**
	 * Working days are counted from the day after the promise.
	 *
	 * @return void
	 */
	public function testWorkingDaysCountFromTheDayAfterThePromise(): void {
		$workDays = $this->createMock(WorkingDayCalculator::class);
		$workDays->expects($this->once())->method('countWorkingDays')
			->with(new DateTimeImmutable('2026-09-10'), new DateTimeImmutable('2026-09-12'))
			->willReturn(2);

		$result = $this->outcome([], [], false, $workDays)->record('case-1', new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-12'));

		$this->assertSame(['status' => 'missed', 'overrunDays' => 2], $result);
	}//end testWorkingDaysCountFromTheDayAfterThePromise()

	/**
	 * Without OpenRegister or a case schema nothing is written or read, and nothing is logged.
	 *
	 * @return void
	 */
	public function testNoStoreOrSchemaIsSilent(): void {
		$this->outcome([], [], true)->record('case-1', new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-08'));
		$this->outcome([], ['case_schema' => ''])->record('case-1', new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-08'));
		$report = $this->outcome([['firstResponseStatus' => 'met']], ['register' => ''])->report();

		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->logged);
		$this->assertSame(['missed' => 0, 'met' => 0, 'averageOverrunDays' => 0.0], $report);
	}//end testNoStoreOrSchemaIsSilent()

	/**
	 * A failing read reports zeros and warns; the filters reach the store.
	 *
	 * @return void
	 */
	public function testAFailedReadReportsZeros(): void {
		$report = $this->outcome(new RuntimeException('timeout'))->report(['caseType' => 'ct-1']);

		$this->assertSame(['missed' => 0, 'met' => 0, 'averageOverrunDays' => 0.0], $report);
		$this->assertSame(['caseType' => 'ct-1'], $this->calls[0][1]['filters']);
		$this->assertSame([['warning', 'Dossiq: the first-response report could not be read: timeout']], $this->logged);
	}//end testAFailedReadReportsZeros()

	/**
	 * Pending and unknown outcomes are not counted; a negative overrun counts as nought; the average rounds to two places.
	 *
	 * @return void
	 */
	public function testTheReportIgnoresPendingAndClampsNegatives(): void {
		$report = $this->outcome([
			['firstResponseStatus' => 'missed', 'firstResponseOverrunDays' => 1],
			['firstResponseStatus' => 'missed', 'firstResponseOverrunDays' => -5],
			['firstResponseStatus' => 'missed', 'firstResponseOverrunDays' => '1'],
			['firstResponseStatus' => 'pending'],
			['firstResponseStatus' => 'MET'],
			[],
			['firstResponseStatus' => 'met'],
		])->report();

		$this->assertSame(['missed' => 3, 'met' => 1, 'averageOverrunDays' => 0.67], $report);
	}//end testTheReportIgnoresPendingAndClampsNegatives()
}//end class
