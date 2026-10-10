<?php

/**
 * Dossiq Woo review reports: throughput per reviewer per day.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserManager;
use Throwable;

/**
 * Counts the Woo document assessments per reviewer per day, split by verdict.
 *
 * OpenRegister's multi-field grouping (`adhoc-aggregation-suite`) is not
 * merged on its `development`, so this groups a paged search of the
 * assessments in PHP. The search is the caller's own, scoped search: RBAC and
 * the active organisation apply, so one organisation never counts another's
 * reviewers. The day is the day of `assessedAt` in the instance's configured
 * zone, read through CaseDateNormaliser (the one class that resolves a zone),
 * so an assessment at 23:30 UTC on a CET instance counts on the next day.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
class WooThroughputReport {

	use SearchesObjects;

	/**
	 * The verdicts, in the order the report shows them.
	 */
	public const VERDICTS = ['openbaar', 'deels_openbaar', 'niet_openbaar'];

	/**
	 * Rows per page of the search.
	 */
	private const PAGE = 500;

	/**
	 * The most pages one report reads; past it the answer says it was cut short.
	 */
	private const MAX_PAGES = 40;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param CaseDateNormaliser $caseDates Reads a moment as a day in the instance's zone.
	 * @param IUserManager $userManager The user manager, for display names.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $caseDates,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The throughput of one Woo case.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When OpenRegister or the assessment schema is not available.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function forCase(string $caseId): array {
		return $this->report(filters: ['caseRef' => $caseId], from: null, to: null);
	}//end forCase()

	/**
	 * The throughput across Woo cases over a period of days, both ends included.
	 *
	 * @param string $from The first day, Y-m-d.
	 * @param string $to The last day, Y-m-d.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When a day is not a date, or OpenRegister is not available.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function forPeriod(string $from, string $to): array {
		foreach ([$from, $to] as $day) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
				throw new RefusedException(
					rule: 'woo-throughput-period',
					sentence: 'Give the period as two dates, from and to.',
					status: RefusedException::STATUS_UNPROCESSABLE,
				);
			}
		}

		return $this->report(filters: [], from: $from, to: $to);
	}//end forPeriod()

	/**
	 * The rows as CSV, with a header line.
	 *
	 * @param list<array<string, int|string>> $rows The report rows.
	 *
	 * @return string The CSV text.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function toCsv(array $rows): string {
		$columns = array_merge(['reviewer', 'displayName', 'day'], self::VERDICTS, ['total']);
		$lines = [self::csvLine(cells: $columns)];
		foreach ($rows as $row) {
			$cells = [];
			foreach ($columns as $column) {
				$cells[] = (string)($row[$column] ?? '');
			}

			$lines[] = self::csvLine(cells: $cells);
		}

		return implode("\r\n", $lines) . "\r\n";
	}//end toCsv()

	/**
	 * Read the assessments and group them.
	 *
	 * @param array<string, mixed> $filters The object filters.
	 * @param string|null $from The first day, or null for no lower bound.
	 * @param string|null $to The last day, or null for no upper bound.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 */
	private function report(array $filters, ?string $from, ?string $to): array {
		$tally = [];
		$cases = [];
		$read = $this->readAssessments(filters: $filters);
		foreach ($read['rows'] as $assessment) {
			$counted = $this->countable(assessment: $assessment, from: $from, to: $to);
			if ($counted === null) {
				continue;
			}

			$this->add(tally: $tally, counted: $counted);
			$cases[(string)($assessment['caseRef'] ?? '')] = true;
		}

		unset($cases['']);
		ksort($tally);
		$rows = [];
		foreach ($tally as $group) {
			$rows[] = ['reviewer' => $group['reviewer'], 'displayName' => $this->displayName(userId: $group['reviewer']), 'day' => $group['day']]
				+ $group['counts'] + ['total' => array_sum($group['counts'])];
		}

		return ['rows' => $rows, 'cases' => array_map('strval', array_keys($cases)), 'truncated' => $read['truncated']];
	}//end report()

	/**
	 * Count one assessment under its reviewer and day.
	 *
	 * @param array<string, array{reviewer: string, day: string, counts: array<string, int>}> $tally The tally so far.
	 * @param array{0: string, 1: string, 2: string} $counted The reviewer, day and verdict.
	 *
	 * @return void
	 */
	private function add(array &$tally, array $counted): void {
		[$reviewer, $day, $verdict] = $counted;
		$key = $reviewer . "\0" . $day;
		if (isset($tally[$key]) === false) {
			$tally[$key] = ['reviewer' => $reviewer, 'day' => $day, 'counts' => array_fill_keys(self::VERDICTS, 0)];
		}

		$tally[$key]['counts'][$verdict] = ((int)($tally[$key]['counts'][$verdict] ?? 0) + 1);
	}//end add()

	/**
	 * The reviewer, day and verdict an assessment counts under, or null when it counts nowhere.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param string|null $from The first day, or null.
	 * @param string|null $to The last day, or null.
	 *
	 * @return array{0: string, 1: string, 2: string}|null The reviewer, day and verdict.
	 */
	private function countable(array $assessment, ?string $from, ?string $to): ?array {
		$reviewer = trim((string)($assessment['assessedBy'] ?? ''));
		$verdict = (string)($assessment['classification'] ?? '');
		$day = $this->caseDates->toCalendarDateOrNull(value: (string)($assessment['assessedAt'] ?? ''));
		if ($reviewer === '' || $day === null || in_array($verdict, self::VERDICTS, true) === false) {
			return null;
		}

		if (($from !== null && $day < $from) || ($to !== null && $day > $to)) {
			return null;
		}

		return [$reviewer, $day, $verdict];
	}//end countable()

	/**
	 * Page through the assessments the caller may read.
	 *
	 * @param array<string, mixed> $filters The object filters.
	 *
	 * @return array{rows: list<array<string, mixed>>, truncated: bool} The rows, and whether the page cap cut them short.
	 *
	 * @throws RefusedException When OpenRegister or the assessment schema is not available.
	 */
	private function readAssessments(array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw $this->unavailable(previous: null);
		}

		$rows = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$batch = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: $filters + ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)],
				);
			} catch (Throwable $e) {
				throw $this->unavailable(previous: $e);
			}

			array_push($rows, ...$batch);
			if (count($batch) < self::PAGE) {
				return ['rows' => $rows, 'truncated' => false];
			}
		}

		return ['rows' => $rows, 'truncated' => true];
	}//end readAssessments()

	/**
	 * The refusal when the assessments cannot be read.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unavailable(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'woo-throughput-unavailable',
			sentence: 'The Woo assessments cannot be read, so the report cannot be made.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unavailable()

	/**
	 * A reviewer's display name, the user id when the user is gone.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string The display name.
	 */
	private function displayName(string $userId): string {
		$name = $this->userManager->getDisplayName($userId);
		if ($name === null || $name === '') {
			return $userId;
		}

		return $name;
	}//end displayName()

	/**
	 * One CSV line. A cell that starts like a formula is prefixed so a spreadsheet does not run it.
	 *
	 * @param list<string> $cells The cells.
	 *
	 * @return string The line.
	 */
	private static function csvLine(array $cells): string {
		$out = [];
		foreach ($cells as $cell) {
			if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) === true) {
				$cell = "'" . $cell;
			}

			$out[] = '"' . str_replace('"', '""', $cell) . '"';
		}

		return implode(',', $out);
	}//end csvLine()
}//end class
