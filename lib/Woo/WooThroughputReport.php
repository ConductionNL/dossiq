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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IConfig;
use OCP\IUserManager;
use Throwable;

/**
 * Counts the Woo document assessments per reviewer per day, split by verdict.
 *
 * OpenRegister's multi-field grouping (`adhoc-aggregation-suite`) is not
 * merged on its `development`, so this groups a paged search of the
 * assessments in PHP. The search is the caller's own, scoped search: RBAC and
 * the active organisation apply, so one organisation never counts another's
 * reviewers. The day is the day of `assessedAt` in the instance time zone, so
 * an assessment at 23:30 UTC on a CET instance counts on the next day.
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
	 * @param IConfig $config The system configuration, for the instance time zone.
	 * @param IUserManager $userManager The user manager, for display names.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IConfig $config,
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
	public static function toCsv(array $rows): string {
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
		$zone = $this->instanceZone();
		$groups = [];
		$cases = [];
		$read = $this->readAssessments(filters: $filters);
		foreach ($read['rows'] as $assessment) {
			$reviewer = trim((string)($assessment['assessedBy'] ?? ''));
			$verdict = (string)($assessment['classification'] ?? '');
			$day = $this->dayOf(moment: (string)($assessment['assessedAt'] ?? ''), zone: $zone);
			if ($reviewer === '' || $day === null || in_array($verdict, self::VERDICTS, true) === false) {
				continue;
			}

			if (($from !== null && $day < $from) || ($to !== null && $day > $to)) {
				continue;
			}

			$key = $reviewer . "\0" . $day;
			if (isset($groups[$key]) === false) {
				$groups[$key] = ['reviewer' => $reviewer, 'day' => $day] + array_fill_keys(self::VERDICTS, 0) + ['total' => 0];
			}

			$groups[$key][$verdict]++;
			$groups[$key]['total']++;
			$case = (string)($assessment['caseRef'] ?? '');
			if ($case !== '') {
				$cases[$case] = true;
			}
		}//end foreach

		ksort($groups);
		$rows = [];
		foreach ($groups as $group) {
			$rows[] = ['reviewer' => $group['reviewer'], 'displayName' => $this->displayName(userId: $group['reviewer'])] + $group;
		}

		return ['rows' => $rows, 'cases' => array_keys($cases), 'truncated' => $read['truncated']];
	}//end report()

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
			throw RefusedException::indeterminate(
				rule: 'woo-throughput-unavailable',
				sentence: 'The Woo assessments cannot be read, so the report cannot be made.',
			);
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
				throw RefusedException::indeterminate(
					rule: 'woo-throughput-unavailable',
					sentence: 'The Woo assessments cannot be read, so the report cannot be made.',
					previous: $e,
				);
			}

			array_push($rows, ...$batch);
			if (count($batch) < self::PAGE) {
				return ['rows' => $rows, 'truncated' => false];
			}
		}

		return ['rows' => $rows, 'truncated' => true];
	}//end readAssessments()

	/**
	 * The instance time zone, UTC when it is unset or not a zone.
	 *
	 * @return DateTimeZone The zone.
	 */
	private function instanceZone(): DateTimeZone {
		try {
			return new DateTimeZone($this->config->getSystemValueString('default_timezone', 'UTC'));
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}//end instanceZone()

	/**
	 * The day of a moment in a zone, or null when the moment is not a date.
	 *
	 * @param string $moment The moment as stored.
	 * @param DateTimeZone $zone The zone the day is read in.
	 *
	 * @return string|null The day, Y-m-d.
	 */
	private function dayOf(string $moment, DateTimeZone $zone): ?string {
		if ($moment === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($moment))->setTimezone($zone)->format('Y-m-d');
		} catch (Throwable) {
			return null;
		}
	}//end dayOf()

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
