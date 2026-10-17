<?php

/**
 * Dossiq TermDashboardFigures.
 *
 * The figures the deadline dashboard leads with: the terms that still run,
 * the ones ending within a week or already past their end, the penalty
 * payments still accruing, and the share decided on time per case type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Computes the deadline dashboard's running figures and on-time shares.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class TermDashboardFigures {
	use SearchesObjects;

	/**
	 * The statuses of a term that still runs.
	 *
	 * @var array<int, string>
	 */
	private const RUNNING = ['lopend', 'paused', 'verlengd'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService Settings and the object service.
	 * @param TermCaseTypeResolver $caseTypes       Resolves each term's case type.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermCaseTypeResolver $caseTypes,
	) {
	}//end __construct()

	/**
	 * The window the share on time covers: the one given, else the quarter
	 * that holds the day.
	 *
	 * @param DateTimeImmutable|null $today The day; now when absent.
	 * @param string|null            $from  First day (YYYY-MM-DD) of a given window.
	 * @param string|null            $until Last day (YYYY-MM-DD) of a given window.
	 *
	 * @return array{from: string, until: string}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function window(?DateTimeImmutable $today, ?string $from, ?string $until): array {
		if ($from !== null && $from !== '') {
			return ['from' => substr($from, 0, 10), 'until' => substr((string)($until ?? '2999-12-31'), 0, 10)];
		}

		$day = ($today ?? new DateTimeImmutable());
		$firstMonth = ((int)floor(((int)$day->format('n') - 1) / 3) * 3) + 1;
		$start = $day->setDate((int)$day->format('Y'), $firstMonth, 1);

		return ['from' => $start->format('Y-m-d'), 'until' => $start->modify('+3 months -1 day')->format('Y-m-d')];
	}//end window()

	/**
	 * Every figure the dashboard adds to the KPI row.
	 *
	 * @param array<int, array<string, mixed>>    $rows    All term instances.
	 * @param DateTimeImmutable|null              $today   The day the figures are read on; now when absent.
	 * @param array<string, array<string, mixed>> $perType The per case type aggregates of the window.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function forDashboard(array $rows, ?DateTimeImmutable $today, array $perType): array {
		return [
			...$this->running(rows: $rows, today: ($today ?? new DateTimeImmutable())),
			...$this->penalties(),
			'onTimeByCaseType' => $this->onTime(perType: $perType),
		];
	}//end forDashboard()

	/**
	 * Count the terms that still run: all of them, those ending within seven
	 * days, those already past their end date, and the case types they span.
	 *
	 * @param array<int, array<string, mixed>> $rows  Instance rows.
	 * @param DateTimeImmutable                $today The day the figures are read on.
	 *
	 * @return array{runningCount:int,runningCaseTypeCount:int,dueThisWeekCount:int,overdueCount:int}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function running(array $rows, DateTimeImmutable $today): array {
		$todayDate = $today->format('Y-m-d');
		$weekEnd = $today->modify('+7 days')->format('Y-m-d');
		$running = array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => in_array((string)($row['status'] ?? ''), self::RUNNING, true)
			)
		);

		$ends = array_filter(array_map(static fn (array $row): string => substr((string)($row['endDateCurrent'] ?? ''), 0, 10), $running));
		$overdue = count(array_filter($ends, static fn (string $end): bool => $end < $todayDate));
		$dueThisWeek = count(array_filter($ends, static fn (string $end): bool => $end >= $todayDate && $end <= $weekEnd));

		$types = $this->caseTypes->resolve(rows: $running);
		$keys = array_unique(array_map(static fn (array $type): string => $type['key'], $types['byRow']));

		return [
			'runningCount' => count($running),
			'runningCaseTypeCount' => count($keys),
			'dueThisWeekCount' => $dueThisWeek,
			'overdueCount' => $overdue,
		];
	}//end running()

	/**
	 * Sum the penalty payments that are still accruing, in euros.
	 *
	 * @return array{penaltyRunningEuros:float,penaltyRunningCount:int}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function penalties(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['penaltyRunningEuros' => 0.0, 'penaltyRunningCount' => 0];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		} catch (\Throwable $e) {
			$rows = [];
		}

		$running = array_filter($rows, static fn (array $row): bool => (string)($row['status'] ?? '') === 'lopend');
		$cents = array_sum(array_map(static fn (array $row): int => (int)($row['cumulativeAmount'] ?? 0), $running));

		return ['penaltyRunningEuros' => round($cents / 100, 2), 'penaltyRunningCount' => count($running)];
	}//end penalties()

	/**
	 * The share of decided terms met per case type, as parallel arrays a
	 * chart reads directly. A case type with nothing decided is left out.
	 *
	 * @param array<string, array<string, mixed>> $perType The per case type aggregates.
	 *
	 * @return array{labels:array<int,string>,shares:array<int,float>}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function onTime(array $perType): array {
		$decided = array_filter($perType, static fn (array $row): bool => $row['binnenTermijnPct'] !== null);

		$labels = [];
		foreach ($decided as $key => $row) {
			$labels[] = (string)$key;
			if ((string)$row['title'] !== '') {
				$labels[array_key_last($labels)] = (string)$row['title'];
			}
		}

		return [
			'labels' => $labels,
			'shares' => array_values(array_map(static fn (array $row): float => (float)$row['binnenTermijnPct'], $decided)),
		];
	}//end onTime()
}//end class
