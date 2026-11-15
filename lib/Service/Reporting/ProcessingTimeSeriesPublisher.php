<?php

/**
 * Hands the processing-time chart series to OpenRegister's analytics leaf.
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

use OCA\OpenRegister\Service\AnalyticsSeriesDraft;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers each chart block of a processing-time report as a page-level
 * series with OpenRegister (ADR-022), so OpenRegister owns persistence and the
 * render contract.
 *
 * Each series is registered under its `dossiq-sla-` key and, for one release,
 * also under its earlier `procest-sla-` key: OpenRegister upserts on the key,
 * so a reader of the old key keeps a current series until it moves.
 * Publication never fails a report; an absent or failing leaf is logged.
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
class ProcessingTimeSeriesPublisher {

	/**
	 * OpenRegister's series service, resolved at run time so dossiq does not
	 * hard-depend on it.
	 *
	 * @var string
	 */
	private const SERIES_SERVICE = 'OCA\OpenRegister\Service\AnalyticsSeriesService';

	/**
	 * The report blocks that publish a series, in publication order.
	 *
	 * @var list<'sla'|'distribution'|'trend'|'throughput'>
	 */
	private const SERIES_BLOCKS = ['sla', 'distribution', 'trend', 'throughput'];

	/**
	 * Each series key => the key it was published under before. Both are
	 * published for one release.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_KEYS = [
		'dossiq-sla-compliance-by-type' => 'procest-sla-compliance-by-type',
		'dossiq-sla-processing-time-distribution' => 'procest-sla-processing-time-distribution',
		'dossiq-sla-monthly-trend' => 'procest-sla-monthly-trend',
		'dossiq-sla-weekly-throughput' => 'procest-sla-weekly-throughput',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's series service.
	 * @param IL10N              $l10n      Words the series titles.
	 * @param LoggerInterface    $logger    Records a failed publication.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register every chart series the report holds data for.
	 *
	 * @param array<string, mixed> $report A processing-time report, whole or narrowed by `view`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
	 */
	public function publish(array $report): void {
		$series = $this->seriesOf(report: $report);
		if ($series === []) {
			return;
		}

		try {
			$this->registerEach(service: $this->container->get(self::SERIES_SERVICE), series: $series);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: processing-time series publication failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end publish()

	/**
	 * Register every series, each also under the key it was published under before.
	 *
	 * @param object                          $service OpenRegister's series service.
	 * @param array<int, array<string, mixed>> $series  The series.
	 *
	 * @return void
	 */
	private function registerEach(object $service, array $series): void {
		foreach ($series as $entry) {
			$service->registerSeries(draft: $this->draftOf(entry: $entry));
			$legacy = (self::LEGACY_KEYS[$entry['seriesKey']] ?? null);
			if ($legacy !== null) {
				$service->registerSeries(draft: $this->draftOf(entry: array_merge($entry, ['seriesKey' => $legacy])));
			}
		}
	}//end registerEach()

	/**
	 * The analytics draft for one series: its data, shown privately as its chart type.
	 *
	 * @param array{seriesKey: string, title: string, chartType?: string, labels: array, datasets: array} $entry The series.
	 *
	 * @return AnalyticsSeriesDraft The draft.
	 *
	 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
	 */
	private function draftOf(array $entry): AnalyticsSeriesDraft {
		return (new AnalyticsSeriesDraft(seriesKey: $entry['seriesKey'], title: $entry['title']))
			->withData(labels: $entry['labels'], datasets: $entry['datasets'])
			->shownAs(chartType: ($entry['chartType'] ?? 'line'), visibility: 'private');
	}//end draftOf()

	/**
	 * The series a report yields, as named arguments for the series service.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<int, array<string, mixed>> One entry per series with data.
	 *
	 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
	 */
	public function seriesOf(array $report): array {
		$series = [];
		foreach (self::SERIES_BLOCKS as $block) {
			$rows = ($report[$block] ?? []);
			if ($rows !== []) {
				$series[] = $this->seriesFor(block: $block, rows: $rows);
			}
		}

		return $series;
	}//end seriesOf()

	/**
	 * The series one report block publishes as.
	 *
	 * @param 'sla'|'distribution'|'trend'|'throughput' $block One of SERIES_BLOCKS.
	 * @param array<int, array<string, mixed>>        $rows  The block's rows.
	 *
	 * @return array<string, mixed> The series.
	 */
	private function seriesFor(string $block, array $rows): array {
		return match ($block) {
			'sla' => $this->complianceSeries(sla: $rows),
			'distribution' => $this->distributionSeries(bins: $rows),
			'trend' => $this->trendSeries(trend: $rows),
			'throughput' => $this->throughputSeries(weeks: $rows),
		};
	}//end seriesFor()

	/**
	 * Compliance by case type, as a doughnut of the cases within the SLA.
	 *
	 * @param array<int, array<string, mixed>> $sla The SLA rows per case type.
	 *
	 * @return array<string, mixed> The series.
	 */
	private function complianceSeries(array $sla): array {
		return [
			'seriesKey' => 'dossiq-sla-compliance-by-type',
			'title'     => $this->l10n->t('Compliance by Case Type'),
			'chartType' => 'doughnut',
			'labels'    => array_column($sla, 'name'),
			'datasets'  => array_column($sla, 'withinSla'),
		];
	}//end complianceSeries()

	/**
	 * The processing time distribution, as a bar per bin.
	 *
	 * @param array<int, array<string, mixed>> $bins The distribution bins.
	 *
	 * @return array<string, mixed> The series.
	 */
	private function distributionSeries(array $bins): array {
		return [
			'seriesKey' => 'dossiq-sla-processing-time-distribution',
			'title'     => $this->l10n->t('Processing Time Distribution'),
			'chartType' => 'bar',
			'labels'    => array_column($bins, 'label'),
			'datasets'  => [['name' => $this->l10n->t('Cases'), 'data' => array_column($bins, 'count')]],
		];
	}//end distributionSeries()

	/**
	 * The monthly SLA trend, as a line of compliance rates.
	 *
	 * @param array<int, array<string, mixed>> $trend The months.
	 *
	 * @return array<string, mixed> The series.
	 */
	private function trendSeries(array $trend): array {
		return [
			'seriesKey' => 'dossiq-sla-monthly-trend',
			'title'     => $this->l10n->t('Monthly SLA Trend'),
			'chartType' => 'line',
			'labels'    => array_column($trend, 'month'),
			'datasets'  => [['name' => $this->l10n->t('SLA Compliance %%'), 'data' => array_column($trend, 'rate')]],
		];
	}//end trendSeries()

	/**
	 * The weekly throughput, as a line of cases closed.
	 *
	 * @param array<int, array<string, mixed>> $weeks The weeks.
	 *
	 * @return array<string, mixed> The series.
	 */
	private function throughputSeries(array $weeks): array {
		return [
			'seriesKey' => 'dossiq-sla-weekly-throughput',
			'title'     => $this->l10n->t('Throughput (cases closed per week)'),
			'chartType' => 'line',
			'labels'    => array_column($weeks, 'weekLabel'),
			'datasets'  => [['name' => $this->l10n->t('Cases closed'), 'data' => array_column($weeks, 'count')]],
		];
	}//end throughputSeries()
}//end class
