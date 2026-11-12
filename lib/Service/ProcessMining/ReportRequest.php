<?php

/**
 * The query parameters of the process-mining report.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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

namespace OCA\Dossiq\Service\ProcessMining;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Reporting\ReportPeriod;

/**
 * Checks the report's query and turns it into the service's filters.
 *
 * The query holds `from`, `to`, `caseType`, `period`, `view`, `format` and
 * `statistic`, each as the request gave it.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
 */
final class ReportRequest {

	/**
	 * Constructor.
	 *
	 * @param ReportPeriod       $periods Resolves a period preset.
	 * @param CaseDateNormaliser $dates   The tenant's today.
	 */
	public function __construct(
		private readonly ReportPeriod $periods,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Why the query is refused, or null when it is acceptable.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return string|null The message of the first offending parameter.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
	 */
	public function refusal(array $query): ?string {
		return ($this->refusedFilter(query: $query) ?? $this->refusedDisplay(query: $query) ?? $this->refusedDownload(query: $query));
	}//end refusal()

	/**
	 * The filter map for the report service.
	 *
	 * Absent and empty-string filters are omitted. A period preset sets `from`
	 * when `from` is not given.
	 *
	 * @param array<string, mixed> $query The accepted query parameters.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 */
	public function filters(array $query): array {
		$query['from'] = ($query['from'] ?? $this->periodStart(period: ($query['period'] ?? null)));
		$params = [];
		foreach (['from', 'to', 'caseType'] as $name) {
			if (is_string($query[$name] ?? null) === true && $query[$name] !== '') {
				$params[$name] = $query[$name];
			}
		}

		return $params;
	}//end filters()

	/**
	 * Refuse a `from`/`to` that is not a calendar `Y-m-d` date, or a non-string `caseType`.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return string|null The message, or null.
	 */
	private function refusedFilter(array $query): ?string {
		foreach (['from', 'to'] as $name) {
			$value = ($query[$name] ?? null);
			if ($value !== null && (is_string($value) === false || $this->isValidDate(value: $value) === false)) {
				return $name.' must be a Y-m-d date';
			}
		}

		if (($query['caseType'] ?? null) !== null && is_string($query['caseType']) === false) {
			return 'caseType must be a string';
		}

		return null;
	}//end refusedFilter()

	/**
	 * Refuse an unknown period preset or view.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return string|null The message, or null.
	 */
	private function refusedDisplay(array $query): ?string {
		$period = ($query['period'] ?? null);
		if ($period !== null && $period !== '' && $this->periods->isPreset(preset: $period) === false) {
			return 'period must be one of '.implode(', ', ReportPeriod::PRESETS);
		}

		$view = ($query['view'] ?? null);
		if ($view !== null && $view !== '' && in_array($view, ReportViews::VIEWS, true) === false) {
			return 'view must be one of '.implode(', ', ReportViews::VIEWS);
		}

		return null;
	}//end refusedDisplay()

	/**
	 * Refuse a download format or statistic the report does not offer.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return string|null The message, or null.
	 */
	private function refusedDownload(array $query): ?string {
		if (in_array(($query['format'] ?? null), ['json', 'csv'], true) === false) {
			return 'format must be json or csv';
		}

		if (in_array(($query['statistic'] ?? null), ['mean', 'median'], true) === false) {
			return 'statistic must be mean or median';
		}

		return null;
	}//end refusedDownload()

	/**
	 * The `from` date a period preset stands for.
	 *
	 * The report reads twelve months when `from` is absent, so `all` is sent
	 * as the earliest date rather than as no date.
	 *
	 * @param mixed $period A validated preset, or null.
	 *
	 * @return string|null The date as `Y-m-d`, or null without a preset.
	 */
	private function periodStart(mixed $period): ?string {
		if (is_string($period) === false || $period === '') {
			return null;
		}

		$from = $this->periods->from(preset: $period, today: $this->dates->today());
		if ($from === null) {
			return '1970-01-01';
		}

		return $from->format('Y-m-d');
	}//end periodStart()

	/**
	 * Whether a string is a zero-padded calendar `Y-m-d` date (`2026-02-30` is not).
	 *
	 * @param string $value Candidate date string.
	 *
	 * @return bool
	 */
	private function isValidDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isValidDate()
}//end class
