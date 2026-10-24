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
			$service = $this->container->get(self::SERIES_SERVICE);
			foreach ($series as $entry) {
				$service->register(...$entry);
				$legacy = (self::LEGACY_KEYS[$entry['seriesKey']] ?? null);
				if ($legacy !== null) {
					$service->register(...array_merge($entry, ['seriesKey' => $legacy]));
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: processing-time series publication failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end publish()

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

		$sla = ($report['sla'] ?? []);
		if ($sla !== []) {
			$series[] = [
				'seriesKey' => 'dossiq-sla-compliance-by-type',
				'title'     => $this->l10n->t('Compliance by Case Type'),
				'chartType' => 'doughnut',
				'labels'    => array_column($sla, 'name'),
				'datasets'  => array_column($sla, 'withinSla'),
			];
		}

		$bins = ($report['distribution'] ?? []);
		if ($bins !== []) {
			$series[] = [
				'seriesKey' => 'dossiq-sla-processing-time-distribution',
				'title'     => $this->l10n->t('Processing Time Distribution'),
				'chartType' => 'bar',
				'labels'    => array_column($bins, 'label'),
				'datasets'  => [['name' => $this->l10n->t('Cases'), 'data' => array_column($bins, 'count')]],
			];
		}

		$trend = ($report['trend'] ?? []);
		if ($trend !== []) {
			$series[] = [
				'seriesKey' => 'dossiq-sla-monthly-trend',
				'title'     => $this->l10n->t('Monthly SLA Trend'),
				'chartType' => 'line',
				'labels'    => array_column($trend, 'month'),
				'datasets'  => [['name' => $this->l10n->t('SLA Compliance %%'), 'data' => array_column($trend, 'rate')]],
			];
		}

		$weeks = ($report['throughput'] ?? []);
		if ($weeks !== []) {
			$series[] = [
				'seriesKey' => 'dossiq-sla-weekly-throughput',
				'title'     => $this->l10n->t('Throughput (cases closed per week)'),
				'chartType' => 'line',
				'labels'    => array_column($weeks, 'weekLabel'),
				'datasets'  => [['name' => $this->l10n->t('Cases closed'), 'data' => array_column($weeks, 'count')]],
			];
		}

		return $series;
	}//end seriesOf()
}//end class
