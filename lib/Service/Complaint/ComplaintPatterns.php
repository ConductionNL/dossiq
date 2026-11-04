<?php

/**
 * Dossiq ComplaintPatterns.
 *
 * The complaint patterns of one quarter against the quarter before, as board
 * DqProcesanalyse draws them ("Klachten: terugkerende patronen"): four figures
 * against the organisation's targets, the system report, the breakdowns and
 * the handling time per category.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;
use Throwable;

/**
 * The complaint patterns answer. It never carries an employee: the
 * per-employee threshold alert goes only to the hr coordinator and the
 * department head.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class ComplaintPatterns {
	use SearchesObjects;

	public const TARGET_PER_MONTH = 'complaint_target_per_month';
	public const TARGET_WITHIN_AWB = 'complaint_target_within_awb_percent';
	public const TARGET_UPHELD = 'complaint_target_upheld_percent';

	/**
	 * The targets when the organisation set none.
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_TARGETS = [
		self::TARGET_PER_MONTH => 10,
		self::TARGET_WITHIN_AWB => 90,
		self::TARGET_UPHELD => 15,
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService The app settings, for the object service, schemas and targets.
	 * @param IL10N                   $l10n            Translates the notes and the report.
	 * @param ComplaintPatternFigures $figures         Counts the patterns.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly ComplaintPatternFigures $figures,
	) {
	}//end __construct()

	/**
	 * The patterns of one quarter.
	 *
	 * @param QuarterPeriod $period The quarter.
	 *
	 * @return array<string, mixed> The answer; `available` false when complaints are not set up.
	 *
	 * @throws Throwable When the complaints cannot be read; an empty answer would read "no complaints".
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function forQuarter(QuarterPeriod $period): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('complaint_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['available' => false];
		}

		$months = $period->trailingMonths();
		$complaints = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'receiptDate' => ['gte' => min($months[0].'-01', $period->previous()->firstDay()), 'lte' => $period->lastDay()],
				'_limit' => 10000,
			]
		);
		$dispositions = $this->dispositions(objectService: $objectService, register: $register);
		$figures = $this->figures->compute(period: $period, complaints: $complaints, dispositions: $dispositions);
		$targets = $this->targets();

		return [
			'available' => true,
			'quarterLabel' => $this->quarterLabel(period: $period),
			'kpis' => (new ComplaintPatternNotes(l10n: $this->l10n))->kpis(figures: $figures, targets: $targets),
			'findings' => $this->findings(period: $period, byCategory: $figures['byCategory']),
			'byCategory' => $figures['byCategory'],
			'byDepartment' => $figures['byDepartment'],
			'byChannel' => $figures['byChannel'],
			'byMonth' => $figures['byMonth'] + ['target' => array_fill(0, count($months), $targets[self::TARGET_PER_MONTH])],
			'resolutionByCategory' => $figures['handling']['resolutionByCategory'],
		];
	}//end forQuarter()

	/**
	 * The final disposition per complaint id; one awaiting approval is not final.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function dispositions(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue('complaint_disposition_schema');
		if ($schema === '') {
			return [];
		}

		$final = [];
		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['_limit' => 10000]);
		foreach ($rows as $row) {
			$complaint = (string)($row['complaint'] ?? '');
			if ($complaint !== '' && (string)($row['approvalStatus'] ?? '') !== 'awaiting_approval') {
				$final[$complaint] = $row;
			}
		}

		return $final;
	}//end dispositions()

	/**
	 * The organisation's targets, each falling back to its default.
	 *
	 * @return array<string, int>
	 */
	private function targets(): array {
		$targets = [];
		foreach (self::DEFAULT_TARGETS as $key => $default) {
			$value = trim((string)$this->settingsService->getConfigValue($key, ''));
			$targets[$key] = $default;
			if (is_numeric($value) === true) {
				$targets[$key] = (int)$value;
			}
		}

		return $targets;
	}//end targets()

	/**
	 * The quarter as a reader names it ("Third quarter 2026").
	 *
	 * @param QuarterPeriod $period The quarter.
	 *
	 * @return string
	 */
	private function quarterLabel(QuarterPeriod $period): string {
		$names = [
			1 => $this->l10n->t('First quarter %s', [(string)$period->year]),
			2 => $this->l10n->t('Second quarter %s', [(string)$period->year]),
			3 => $this->l10n->t('Third quarter %s', [(string)$period->year]),
			4 => $this->l10n->t('Fourth quarter %s', [(string)$period->year]),
		];

		return $names[$period->quarter];
	}//end quarterLabel()

	/**
	 * The system report: one finding naming every category that rose past the flag.
	 *
	 * @param QuarterPeriod                      $period     The quarter.
	 * @param array{labels: list<string>, counts: list<int>, risePercent: list<int|null>, previousCounts: list<int>} $byCategory The category counts.
	 *
	 * @return list<array{title: string, text: string}>
	 */
	private function findings(QuarterPeriod $period, array $byCategory): array {
		$parts = [];
		foreach ($byCategory['risePercent'] as $index => $rise) {
			if ($rise !== null) {
				$count = $byCategory['counts'][$index];
				$before = (string)$byCategory['previousCounts'][$index];
				$parts[] = $this->l10n->n(
					'%1$s (%2$s complaint, from %3$s to %2$s)',
					'%1$s (%2$s complaints, from %3$s to %2$s)',
					$count,
					[$byCategory['labels'][$index], (string)$count, $before]
				);
			}
		}

		if ($parts === []) {
			return [];
		}

		return [[
			'title' => $this->l10n->n(
				'%n category rose by more than half in the %s',
				'%n categories rose by more than half in the %s',
				count($parts),
				[mb_strtolower($this->quarterLabel(period: $period))]
			),
			'text' => implode('; ', $parts).'.',
		]];
	}//end findings()
}//end class
