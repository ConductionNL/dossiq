<?php

/**
 * The processing-time report: every figure the processing-time dashboard
 * draws, shaped on the server.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCP\IL10N;

/**
 * Loads the records, record types and status types once and answers the
 * blocks a dashboard widget reads through `responsePath`:
 *
 * - `sla`: target compliance per record type with at least one completed
 *   record, plus `slaSummary` for the overall figure;
 * - `distribution`: processing-time histogram bins, empty when every bin is,
 *   plus `distributionTarget`;
 * - `trend`: monthly compliance; `throughput`: records closed per week;
 * - `atRisk`: open records near or past their target;
 * - `breakdown`: performance per record type.
 *
 * `view` narrows the answer to one block so a widget does not receive the
 * others. `truncated` says the load hit its limit, so the figures are short.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeReportService {

	/**
	 * The blocks a `view` may name.
	 *
	 * @var string[]
	 */
	public const VIEWS = ['sla', 'distribution', 'trend', 'throughput', 'atRisk', 'breakdown'];

	/**
	 * The remaining share of the target at or below which an open record is
	 * listed as at risk, unless the request names another.
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 0.25;

	/**
	 * Constructor.
	 *
	 * @param ProcessMiningDataLoader     $loader     Reads records, types and statuses.
	 * @param ProcessingTimeCalculator    $calculator The chart arithmetic.
	 * @param ProcessingTimeRows          $rows       The table rows.
	 * @param ProcessingTimeSeriesPublisher $publisher Hands the chart series to the analytics leaf.
	 * @param CaseDateNormaliser          $dates      Reads stored dates in the tenant's zone.
	 * @param ReportPeriod                $periods    Resolves the period preset.
	 * @param IL10N                       $l10n       Words the labels in the reader's language.
	 * @param LanguageMapText             $text       Reads multilingual titles.
	 */
	public function __construct(
		private readonly ProcessMiningDataLoader $loader,
		private readonly ProcessingTimeCalculator $calculator,
		private readonly ProcessingTimeRows $rows,
		private readonly ProcessingTimeSeriesPublisher $publisher,
		private readonly CaseDateNormaliser $dates,
		private readonly ReportPeriod $periods,
		private readonly IL10N $l10n,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Build the report.
	 *
	 * @param string      $period    A {@see ReportPeriod::PRESETS} key.
	 * @param string|null $caseType  A record type id or slug to narrow to, or null.
	 * @param string|null $view      One of {@see self::VIEWS}, or null for every block.
	 * @param float       $threshold The at-risk threshold, 0 to 1.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function getReport(string $period, ?string $caseType, ?string $view, float $threshold): array {
		$today   = $this->dates->today();
		$records = $this->loader->loadCases(caseTypeFilter: null);
		$types   = $this->prepareTypes(rows: $this->loader->loadCaseTypes());
		$finals  = $this->finalStatusIds(rows: $this->loader->loadStatusTypes());

		$typeId  = $this->resolveType(types: $types, key: $caseType);
		$typeIds = $this->resolveTypes(types: $types, key: $caseType);
		$scoped  = [];
		foreach ($records as $row) {
			$record = $this->prepareRecord(row: $row, types: $types, finals: $finals);
			if ($caseType === null || in_array($record['caseType'], $typeIds, true) === true) {
				$scoped[] = $record;
			}
		}

		$completed = array_values(
			array_filter(
				$scoped,
				static fn (array $record): bool => $record['isFinal'] === true && $record['endDate'] !== null
			)
		);
		$open      = array_values(array_filter($scoped, static fn (array $record): bool => $record['isFinal'] === false));
		$from      = $this->periods->from(preset: $period, today: $today);
		$inPeriod  = $this->inPeriod(records: $completed, from: $from);

		$context = [
			'today'     => $today,
			'types'     => $types,
			'completed' => $completed,
			'inPeriod'  => $inPeriod,
			'open'      => $open,
			'months'    => $this->periods->trendMonths(preset: $period, today: $today),
			'threshold' => $threshold,
		];

		$report = [
			'period'    => [
				'preset' => $period,
				'from'   => $from?->format('Y-m-d'),
				'to'     => $today->format('Y-m-d'),
			],
			'caseType'  => $typeId,
			'truncated' => (count($records) >= ProcessMiningDataLoader::CASE_LIMIT),
		];

		foreach (self::VIEWS as $block) {
			if ($view === null || $view === $block) {
				$report = [...$report, ...$this->block(name: $block, context: $context)];
			}
		}

		if ($caseType === null) {
			$this->publisher->publish(report: $report);
		}

		return $report;
	}//end getReport()

	/**
	 * One named block of the report.
	 *
	 * @param string               $name    One of {@see self::VIEWS}.
	 * @param array<string, mixed> $context The prepared inputs.
	 *
	 * @return array<string, mixed> The block's keys.
	 */
	private function block(string $name, array $context): array {
		return match ($name) {
			'sla' => $this->slaBlock(context: $context),
			'distribution' => $this->distributionBlock(context: $context),
			'trend' => [
				'trend' => $this->calculator->monthlyTrend(
					completed: $context['completed'],
					types: $context['types'],
					months: $context['months'],
					today: $context['today'],
				),
			],
			'throughput' => ['throughput' => $this->calculator->weeklyThroughput(completed: $context['inPeriod'])],
			'atRisk' => ['atRisk' => $this->labelAtRisk(rows: $this->rows->atRisk(
				open: $context['open'],
				types: $context['types'],
				threshold: $context['threshold'],
				today: $context['today'],
			))],
			default => ['breakdown' => $this->labelBreakdown(rows: $this->rows->performance(
				completed: $context['inPeriod'],
				types: $context['types'],
			))],
		};
	}//end block()

	/**
	 * The compliance block: per-type rows that hold a completed record.
	 *
	 * @param array<string, mixed> $context The prepared inputs.
	 *
	 * @return array{sla: array<int, array<string, mixed>>, slaSummary: array<string, mixed>}
	 */
	private function slaBlock(array $context): array {
		$compliance = $this->calculator->slaCompliance(completed: $context['inPeriod'], types: $context['types']);

		return ['sla' => $compliance['byType'], 'slaSummary' => $compliance['summary']];
	}//end slaBlock()

	/**
	 * The histogram block, empty when no bin holds a record.
	 *
	 * @param array<string, mixed> $context The prepared inputs.
	 *
	 * @return array{distribution: array<int, array{label: string, count: int}>, distributionTarget: array<string, mixed>|null}
	 */
	private function distributionBlock(array $context): array {
		$histogram = $this->calculator->distribution(completed: $context['inPeriod'], types: $context['types']);
		$bins      = $histogram['bins'];
		if (array_sum(array_column($bins, 'count')) === 0) {
			$bins = [];
		}

		$target = null;
		if ($histogram['slaTargetDays'] !== null) {
			$target = [
				'days'  => $histogram['slaTargetDays'],
				'bin'   => $histogram['targetBin'],
				'label' => str_replace('{days}', (string)$histogram['slaTargetDays'], $this->l10n->t('SLA Target: {days}d')),
			];
		}

		return ['distribution' => $bins, 'distributionTarget' => $target];
	}//end distributionBlock()

	/**
	 * Add the severity and remaining-time words to at-risk rows, so a table
	 * shows text beside the colour.
	 *
	 * @param array<int, array<string, mixed>> $rows The calculator's rows.
	 *
	 * @return array<int, array<string, mixed>> The rows with labels.
	 */
	private function labelAtRisk(array $rows): array {
		$severities = [
			'overdue'  => $this->l10n->t('Overdue'),
			'critical' => $this->l10n->t('Critical'),
			'warning'  => $this->l10n->t('At risk'),
			'ok'       => $this->l10n->t('On track'),
		];

		foreach ($rows as $index => $row) {
			$remaining = (int)$row['remainingDays'];
			$label     = $this->l10n->n('%n day remaining', '%n days remaining', $remaining);
			if ($remaining < 0) {
				$label = $this->l10n->n('%n day late', '%n days late', abs($remaining));
			}

			if ($remaining === 0) {
				$label = $this->l10n->t('Due today');
			}

			$rows[$index]['severityLabel']  = $severities[$row['severity']];
			$rows[$index]['remainingLabel'] = $label;
		}

		return $rows;
	}//end labelAtRisk()

	/**
	 * Add the status word to breakdown rows, so the status dot is not the only
	 * signal.
	 *
	 * @param array<int, array<string, mixed>> $rows The calculator's rows.
	 *
	 * @return array<int, array<string, mixed>> The rows with labels.
	 */
	private function labelBreakdown(array $rows): array {
		$statuses = [
			'good'      => $this->l10n->t('On track'),
			'warning'   => $this->l10n->t('Needs attention'),
			'critical'  => $this->l10n->t('Critical'),
			'no-target' => $this->l10n->t('No target'),
		];

		foreach ($rows as $index => $row) {
			$rows[$index]['statusLabel'] = $statuses[$row['status']];
		}

		return $rows;
	}//end labelBreakdown()

	/**
	 * Record types keyed by id, with their name and target in days.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored record types.
	 *
	 * @return array<string, array{id: string, slug: string, name: string, targetDays: int|null}>
	 */
	private function prepareTypes(array $rows): array {
		$types = [];
		foreach ($rows as $row) {
			$typeId = (string)($row['id'] ?? '');
			if ($typeId === '') {
				continue;
			}

			$name = $this->text->textOf(value: ($row['title'] ?? null));
			if ($name === '') {
				$name = $this->text->textOr(value: ($row['name'] ?? null), fallback: $this->l10n->t('Unknown'));
			}

			$types[$typeId] = [
				'id'         => $typeId,
				'slug'       => (string)($row['slug'] ?? ''),
				'keys'       => $this->typeKeys(row: $row),
				'family'     => $this->typeFamily(row: $row, typeId: $typeId),
				'name'       => $name,
				'targetDays' => $this->calculator->durationToDays(duration: ($row['processingDeadline'] ?? null)),
			];
		}//end foreach

		return $types;
	}//end prepareTypes()

	/**
	 * The id of the record type a filter names by id or slug.
	 *
	 * @param array<string, array<string, mixed>> $types Prepared types.
	 * @param string|null                         $key   The filter.
	 *
	 * @return string|null The type id; the filter itself when no type answers to it.
	 */
	private function resolveType(array $types, ?string $key): ?string {
		if ($key === null || isset($types[$key]) === true) {
			return $key;
		}

		foreach ($types as $typeId => $type) {
			if ($type['slug'] === $key) {
				return $typeId;
			}
		}

		return $key;
	}//end resolveType()

	/**
	 * Every record type a filter names: the type with that id, or every type
	 * that answers to it as slug or identifier, so a filter by identifier
	 * covers each version of the type (Woo-verzoek v1 and v2 share one).
	 *
	 * @param array<string, array<string, mixed>> $types Prepared types.
	 * @param string|null                         $key   The filter.
	 *
	 * @return list<string> The type ids; the filter itself when no type answers to it.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function resolveTypes(array $types, ?string $key): array {
		if ($key === null) {
			return [];
		}

		if (isset($types[$key]) === true) {
			return [$key];
		}

		$ids = [];
		foreach ($types as $typeId => $type) {
			if (in_array($key, $type['keys'], true) === true) {
				$ids[] = (string)$typeId;
			}
		}

		if ($ids === []) {
			return [$key];
		}

		return $ids;
	}//end resolveTypes()

	/**
	 * The case type a version belongs to: its identifier, which every version
	 * of the type shares, else its own id. The breakdown reads one row per
	 * family, not per version.
	 *
	 * @param array<string, mixed> $row    The stored type.
	 * @param string               $typeId The type's id.
	 *
	 * @return string The family key.
	 */
	private function typeFamily(array $row, string $typeId): string {
		$identifier = (string)($row['identifier'] ?? '');
		if ($identifier !== '') {
			return $identifier;
		}

		return $typeId;
	}//end typeFamily()

	/**
	 * The names a record type answers to besides its id: its slug, its
	 * OpenRegister slug and its identifier.
	 *
	 * @param array<string, mixed> $row The stored type.
	 *
	 * @return list<string> The names.
	 */
	private function typeKeys(array $row): array {
		$selfSlug = '';
		if (is_array($row['@self'] ?? null) === true) {
			$selfSlug = (string)($row['@self']['slug'] ?? '');
		}

		$keys = [
			(string)($row['slug'] ?? ''),
			$selfSlug,
			(string)($row['identifier'] ?? ''),
		];

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end typeKeys()

	/**
	 * One stored record reduced to what the calculator reads.
	 *
	 * A record that names its type by slug is filed under the type's id.
	 *
	 * @param array<string, mixed>                $row    The stored record.
	 * @param array<string, array<string, mixed>> $types  Prepared types.
	 * @param array<string, true>                 $finals Ids of the final status types.
	 *
	 * @return array<string, mixed> The prepared record.
	 */
	private function prepareRecord(array $row, array $types, array $finals): array {
		return [
			'id'         => (string)($row['id'] ?? ''),
			'title'      => (string)($row['title'] ?? ''),
			'identifier' => (string)($row['identifier'] ?? ''),
			'caseType'   => (string)$this->resolveType(types: $types, key: (string)($row['caseType'] ?? '')),
			'startDate'  => $this->dates->toCalendarDateOrNull(value: ($row['startDate'] ?? null)),
			'endDate'    => $this->dates->toCalendarDateOrNull(value: ($row['endDate'] ?? null)),
			'deadline'   => $this->dates->toCalendarDateOrNull(value: ($row['deadline'] ?? null)),
			'isFinal'    => isset($finals[(string)($row['status'] ?? '')]),
		];
	}//end prepareRecord()

	/**
	 * The ids of the status types that close a record.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored status types.
	 *
	 * @return array<string, true> The final ids as keys.
	 */
	private function finalStatusIds(array $rows): array {
		$finals = [];
		foreach ($rows as $row) {
			$isFinal = ($row['isFinal'] ?? false);
			if (in_array($isFinal, [true, 'true', 1, '1'], true) === true) {
				$finals[(string)($row['id'] ?? '')] = true;
			}
		}

		return $finals;
	}//end finalStatusIds()

	/**
	 * The completed records that ended on or after the period's first day.
	 *
	 * @param array<int, array<string, mixed>> $records Completed records.
	 * @param DateTimeImmutable|null           $from    The first day, or null for every record.
	 *
	 * @return array<int, array<string, mixed>> The records inside the period.
	 */
	private function inPeriod(array $records, ?DateTimeImmutable $from): array {
		if ($from === null) {
			return $records;
		}

		$first = $from->format('Y-m-d');

		return array_values(
			array_filter($records, static fn (array $record): bool => (string)$record['endDate'] >= $first)
		);
	}//end inPeriod()
}//end class
