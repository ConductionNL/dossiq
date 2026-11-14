<?php

/**
 * ProcessMiningService golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ProcessMining\ProcessMiningAnalysers;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator;
use OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder;
use OCA\Dossiq\Service\ProcessMiningService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\StatusDwellService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ProcessMiningService::getReport() over seeded stores.
 *
 * @covers \OCA\Dossiq\Service\ProcessMiningService
 * @covers \OCA\Dossiq\Service\ProcessMining\ProcessMiningAnalysers
 * @uses \OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer
 * @uses \OCA\Dossiq\Service\ProcessMining\DwellWindow
 * @uses \OCA\Dossiq\Service\ProcessMining\CaseDwell
 * @uses \OCA\Dossiq\Service\ProcessMining\StatusVisit
 * @uses \OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader
 * @uses \OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator
 * @uses \OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\ProcessMining\PhaseSummary
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 * @uses \OCA\Dossiq\Service\Status\StatusDwellService
 * @uses \OCA\Dossiq\Service\ProcessMining\StatusLabels
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ProcessMiningServiceGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;
	use MatchesGoldenFiles;

	/**
	 * Build the report over one seeded world.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows   Rows per schema.
	 * @param array<string, mixed>                            $params The report parameters.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report(array $rows, array $params): array {
		$objects = new FakeTermijnStore();
		foreach ($rows as $schema => $list) {
			foreach ($list as $row) {
				$objects->seed($schema, $row);
			}
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
				'status_record_schema' => 'statusRecord',
				default => '',
			},
		);

		$dates = $this->caseDatesFrozenAt();
		$held = new StatusDwellService(calendar: $this->createMock(originalClassName: WorkingDayCalculator::class), dates: $dates);
		$service = new ProcessMiningService(
			dataLoader: new ProcessMiningDataLoader(settingsService: $settings),
			analysers: new ProcessMiningAnalysers(
				dwellTimeAnalyzer: new DwellTimeAnalyzer(dates: $dates, held: $held),
				transitionBuilder: new TransitionMatrixBuilder(),
				throughputCalculator: new ThroughputTrendCalculator(dates: $dates),
			),
			dates: $dates,
		);

		return $service->getReport(params: $params);
	}//end report()

	/**
	 * Replace every number with a marker, for worlds whose figures run on the wall clock.
	 *
	 * @param mixed $value The observed value.
	 *
	 * @return mixed The value without numbers.
	 */
	private function withoutNumbers(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->withoutNumbers(value: $item), $value);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return '<n>';
		}

		return $value;
	}//end withoutNumbers()

	/**
	 * Every report shape, recorded once and compared on each run.
	 *
	 * @return void
	 */
	public function testGoldenReport(): void {
		$types = [
			'caseType' => [
				['id' => 'ct-1', 'title' => 'Omgevingsvergunning', 'slug' => 'omgeving'],
				['id' => 'ct-2', 'title' => ['nl' => 'Melding', 'en' => 'Report']],
			],
			'statusType' => [
				['id' => 'st-in', 'name' => 'Intake', 'phase' => 'Ontvangst'],
				['id' => 'st-rev', 'name' => 'Review', 'phase' => 'Beoordeling'],
				['id' => 'st-out', 'name' => 'Besluit', 'phase' => 'Afronding'],
			],
		];
		$closed = static fn (string $id, string $type, string $end): array => [
			'id' => $id, 'caseType' => $type, 'assignee' => 'fatma', 'startDate' => '2026-03-02', 'endDate' => $end . 'T17:00:00+00:00',
		];
		$record = static fn (string $id, string $case, string $status, string $at): array => [
			'id' => $id, 'case' => $case, 'statusType' => $status, 'createdAt' => $at,
		];
		$two = $types + [
			'case' => [
				$closed('case-1', 'ct-1', '2026-03-20') + [
					'statusDwellTotals' => [['statusType' => 'st-in', 'workingDays' => 3], ['statusType' => 'st-rev', 'workingDays' => 2]],
				],
				$closed('case-2', 'omgeving', '2026-04-10'),
				$closed('case-3', 'ct-2', '2026-05-15'),
				$closed('case-4', 'ct-2', '2026-06-15'),
			],
			'statusRecord' => [
				$record('sr-1', 'case-1', 'st-in', '2026-03-02T09:00:00+00:00'),
				$record('sr-2', 'case-1', 'st-rev', '2026-03-05T09:00:00+00:00'),
				$record('sr-3', 'case-1', 'st-in', '2026-03-09T09:00:00+00:00'),
				$record('sr-4', 'case-1', 'st-out', '2026-03-16T09:00:00+00:00'),
				$record('sr-5', 'case-2', 'st-in', '2026-03-02T09:00:00+00:00'),
				$record('sr-6', 'case-2', 'st-out', '2026-04-01T09:00:00+00:00'),
				$record('sr-7', 'case-3', 'st-rev', '2026-05-01T09:00:00+00:00'),
				$record('sr-8', 'case-9', 'st-rev', '2026-05-01T09:00:00+00:00'),
				$record('sr-9', 'case-4', 'st-in', '2026-06-01T09:00:00+00:00'),
			],
		];
		$history = $types + [
			'case' => [
				$closed('case-1', 'ct-2', '2026-06-10') + [
					'statusHistory' => '[{"status":"st-in","enteredAt":"2026-06-01T09:00:00+02:00","actor":"lars"},'
						. '{"status":"st-rev","enteredAt":"2026-06-03T09:00:00+02:00"}]',
				],
			],
		];
		$open = $types + [
			'case' => [['id' => 'case-1', 'caseType' => 'ct-1', 'startDate' => '2026-03-02', 'endDate' => null]],
			'statusRecord' => [$record('sr-1', 'case-1', 'st-in', '2026-03-02T09:00:00+00:00')],
		];
		$worlds = [
			'two case types over a year' => [$two, ['from' => '2026-01-01', 'to' => '2026-12-31'], false],
			'filtered by case type' => [$two, ['from' => '2026-01-01', 'to' => '2026-12-31', 'caseType' => 'ct-2'], false],
			'filtered by a case type nobody has' => [$two, ['from' => '2026-01-01', 'to' => '2026-12-31', 'caseType' => 'ct-9'], false],
			'narrow window' => [$two, ['from' => '2026-03-04', 'to' => '2026-03-10'], false],
			'from empty falls back to a year back' => [$two, ['from' => '', 'to' => '2026-06-30'], false],
			'from unreadable falls back to a year back' => [$two, ['from' => 'soon', 'to' => '2026-06-30'], false],
			'to unreadable is today' => [$two, ['from' => '2026-01-01', 'to' => 'later'], false],
			'no parameters' => [$two, [], false],
			'status history only' => [$history, ['from' => '2026-01-01', 'to' => '2026-12-31'], false],
			'an open case runs on the wall clock' => [$open, ['from' => '2026-01-01', 'to' => '2026-12-31'], true],
			'nothing stored' => [[], ['from' => '2026-01-01', 'to' => '2026-12-31'], false],
		];

		foreach ($worlds as $name => [$rows, $params, $clockBound]) {
			$report = $this->report(rows: $rows, params: $params);
			if ($clockBound === true) {
				$report = $this->withoutNumbers(value: $report);
			}

			$file = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
			$this->assertMatchesGolden(name: 'process-mining-report/' . $file, value: [$name => $report]);
		}
	}//end testGoldenReport()

	/**
	 * A loaded case without an id is left out of the report and of the throughput count.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsLeftOut(): void {
		$loader = $this->createMock(originalClassName: ProcessMiningDataLoader::class);
		$loader->method('loadCases')->willReturn([
			['id' => '', 'caseType' => 'ct-1', 'endDate' => '2026-03-20T17:00:00+00:00'],
			['caseType' => 'ct-1'],
			['id' => 'case-1', 'caseType' => 'ct-2'],
		]);
		$loader->method('loadCaseTypes')->willReturn([]);
		$loader->method('loadStatusTypes')->willReturn([]);
		$loader->method('loadStatusRecords')->willReturn([]);
		$loader->method('indexById')->willReturn([]);
		$loader->method('indexByIdAndSlug')->willReturn([]);

		$dates = $this->caseDatesFrozenAt();
		$service = new ProcessMiningService(
			dataLoader: $loader,
			analysers: new ProcessMiningAnalysers(
				dwellTimeAnalyzer: new DwellTimeAnalyzer(dates: $dates),
				transitionBuilder: new TransitionMatrixBuilder(),
				throughputCalculator: new ThroughputTrendCalculator(dates: $dates),
			),
			dates: $dates,
		);
		$report = $service->getReport(params: ['from' => '2026-01-01', 'to' => '2026-12-31']);

		self::assertSame(expected: ['ct-2'], actual: array_column($report['caseTypes'], 'id'));
		self::assertSame(expected: 1, actual: $report['caseTypes'][0]['caseVolume']);
	}//end testACaseWithoutAnIdIsLeftOut()
}//end class
