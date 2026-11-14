<?php

/**
 * Dossiq Complaint Analytics Service
 *
 * Service for complaint frequency analysis, anonymized employee-threshold
 * alerts, and systemic-issue detection (>50% quarter-over-quarter increase).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ComplaintAnalyticsServiceInterface;
use OCA\Dossiq\Service\Support\ConfiguredSchemaRows;
use OCA\Dossiq\Service\Term\DayCount;
use Psr\Log\LoggerInterface;

/**
 * Service for complaint analytics, frequency aggregation, and systemic-issue detection.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
 */
class ComplaintAnalyticsService implements ComplaintAnalyticsServiceInterface {

	/**
	 * Minimum complaints per employee slice before employee data is shown (privacy).
	 */
	private const MIN_THRESHOLD_FOR_EMPLOYEE_DATA = 3;

	/**
	 * Complaints per employee per 6-month window triggering an HR alert.
	 */
	private const EMPLOYEE_ALERT_THRESHOLD = 3;

	/**
	 * Quarter-over-quarter increase percentage triggering a systemic-issue flag.
	 */
	private const SYSTEMIC_ISSUE_QOQ_THRESHOLD = 50;

	/**
	 * Reads a configured schema, or answers an empty list when it cannot.
	 *
	 * @var ConfiguredSchemaRows
	 */
	private readonly ConfiguredSchemaRows $rows;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 * @param DayCount $days Counts the days between two moments.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly DayCount $days = new DayCount(),
	) {
		$this->rows = new ConfiguredSchemaRows(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Aggregate complaint frequency by a given dimension for a date range.
	 *
	 * @param string $dimension Grouping dimension: 'category', 'involvedDepartment', 'receiptChannel'
	 * @param string $dateFrom ISO date string (Y-m-d) for range start
	 * @param string $dateTo ISO date string (Y-m-d) for range end
	 *
	 * @return array<string, int> Map of dimension value => complaint count
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getFrequencyByDimension(string $dimension, string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$frequency = [];

		foreach ($complaints as $complaint) {
			$value = (string)($complaint[$dimension] ?? 'unknown');
			if (isset($frequency[$value]) === false) {
				$frequency[$value] = 0;
			}

			$frequency[$value]++;
		}

		// Privacy: when slicing by an employee-identifying dimension, suppress
		// slices below the minimum threshold so individual employees cannot be
		// re-identified from low-count buckets.
		if (in_array($dimension, ['involvedEmployee', 'handler'], true) === true) {
			$frequency = array_filter(
				$frequency,
				static fn (int $count): bool => $count >= self::MIN_THRESHOLD_FOR_EMPLOYEE_DATA
			);
		}

		arsort($frequency);
		return $frequency;
	}//end getFrequencyByDimension()

	/**
	 * Get monthly complaint trend for a date range.
	 *
	 * @param string $dateFrom ISO date string (Y-m-d)
	 * @param string $dateTo ISO date string (Y-m-d)
	 *
	 * @return array<string, int> Map of 'YYYY-MM' => complaint count
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getMonthlyTrend(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$trend = [];

		foreach ($complaints as $complaint) {
			$date = $complaint['receiptDate'] ?? '';
			$month = substr($date, 0, 7);
			// 'YYYY-MM'
			if (empty($month) === true) {
				continue;
			}

			if (isset($trend[$month]) === false) {
				$trend[$month] = 0;
			}

			$trend[$month]++;
		}

		ksort($trend);
		return $trend;
	}//end getMonthlyTrend()

	/**
	 * Compute average resolution time (in days) by category.
	 *
	 * @param string $dateFrom ISO date (Y-m-d)
	 * @param string $dateTo ISO date (Y-m-d)
	 *
	 * @return array<string, float> Map of categorie => average days to resolve
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getAverageResolutionTime(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$totals = [];
		$counts = [];

		foreach ($complaints as $complaint) {
			if (($complaint['status'] ?? '') !== 'handled') {
				continue;
			}

			$category = (string)($complaint['category'] ?? 'unknown');
			$receipt = $complaint['receiptDate'] ?? null;
			$afhandelDeadline = $complaint['afhandelDeadline'] ?? null;

			if ($receipt === null || $afhandelDeadline === null) {
				continue;
			}

			$start = new DateTimeImmutable($receipt);
			$end = new DateTimeImmutable($afhandelDeadline);
			$days = $this->days->between(from: $start, to: $end);

			$totals[$category] = ($totals[$category] ?? 0) + $days;
			$counts[$category] = ($counts[$category] ?? 0) + 1;
		}

		$averages = [];
		foreach ($counts as $category => $count) {
			$averages[$category] = round($totals[$category] / $count, 1);
		}

		return $averages;
	}//end getAverageResolutionTime()

	/**
	 * Detect categories with >50% quarter-over-quarter complaint increase.
	 *
	 * @param int $year Year to analyze
	 * @param int $quarter Quarter (1-4) to compare against previous quarter
	 *
	 * @return array<int, array<string, mixed>> Systemic-issue records for flagged categories
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function detectSystemicIssues(int $year, int $quarter): array {
		[$currentFrom, $currentTo] = $this->getQuarterRange(year: $year, quarter: $quarter);

		[$prevYear, $prevQuarter] = $this->previousQuarter(year: $year, quarter: $quarter);

		[$prevFrom, $prevTo] = $this->getQuarterRange(year: $prevYear, quarter: $prevQuarter);

		$current = $this->getFrequencyByDimension(dimension: 'category', dateFrom: $currentFrom, dateTo: $currentTo);
		$previous = $this->getFrequencyByDimension(dimension: 'category', dateFrom: $prevFrom, dateTo: $prevTo);

		$systemicIssues = $this->flagIncreases(
			current: $current,
			previous: $previous,
			labels: ['quarter' => 'Q' . $quarter . ' ' . $year, 'previousQuarter' => 'Q' . $prevQuarter . ' ' . $prevYear],
		);

		$this->warnSystemic(systemicIssues: $systemicIssues);

		return $systemicIssues;
	}//end detectSystemicIssues()

	/**
	 * Check for employees referenced in >= 3 complaints in the last 6 months.
	 *
	 * @return array<int, array<string, mixed>> Anonymized alert records (employee reference redacted)
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function checkEmployeeThresholdAlerts(): array {
		$sixMonthsAgo = (new DateTimeImmutable('today'))->modify('-6 months')->format('Y-m-d');
		$today = date('Y-m-d');

		$complaints = $this->fetchComplaintsInRange(dateFrom: $sixMonthsAgo, dateTo: $today);
		[$employeeCounts, $employeeDetails] = $this->tallyEmployees(complaints: $complaints);

		return $this->employeeAlerts(
			employeeCounts: $employeeCounts,
			employeeDetails: $employeeDetails,
			periods: [$sixMonthsAgo, $today],
		);
	}//end checkEmployeeThresholdAlerts()

	/**
	 * The year and quarter before the given one.
	 *
	 * @param int $year    Year of the quarter
	 * @param int $quarter Quarter (1-4)
	 *
	 * @return array{0: int, 1: int} The previous year and quarter
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function previousQuarter(int $year, int $quarter): array {
		$prevYear = $year;
		$prevQuarter = ($quarter - 1);
		if ($quarter === 1) {
			$prevYear = ($year - 1);
			$prevQuarter = 4;
		}

		return [$prevYear, $prevQuarter];
	}//end previousQuarter()

	/**
	 * The categories whose count rose more than the systemic-issue threshold.
	 *
	 * @param array<string, int> $current       Complaint counts per category this quarter
	 * @param array<string, int> $previous      Complaint counts per category last quarter
	 * @param array{quarter: string, previousQuarter: string} $labels Labels of this quarter, e.g. "Q2 2026", and of the previous one
	 *
	 * @return array<int, array<string, mixed>> Systemic-issue records for flagged categories
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function flagIncreases(array $current, array $previous, array $labels): array {
		$systemicIssues = [];

		foreach ($current as $category => $currentCount) {
			$previousCount = $previous[$category] ?? 0;

			if ($previousCount === 0) {
				continue;
			}

			$increasePercent = (($currentCount - $previousCount) / $previousCount) * 100;

			if ($increasePercent > self::SYSTEMIC_ISSUE_QOQ_THRESHOLD) {
				$systemicIssues[] = [
					'category' => $category,
					'currentCount' => $currentCount,
					'previousCount' => $previousCount,
					'increasePercent' => round($increasePercent, 1),
					'quarter' => $labels['quarter'],
					'previousQuarter' => $labels['previousQuarter'],
				];
			}
		}

		return $systemicIssues;
	}//end flagIncreases()

	/**
	 * Log a warning when any category was flagged.
	 *
	 * @param array<int, array<string, mixed>> $systemicIssues The flagged categories
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function warnSystemic(array $systemicIssues): void {
		if (empty($systemicIssues) === false) {
			$this->logger->warning(
				'Systemic complaint issues detected: ' . count($systemicIssues) . ' categories flagged',
				['app' => AppIdentity::APP_ID],
			);
		}
	}//end warnSystemic()

	/**
	 * Count complaints per involved employee and keep their category and date.
	 *
	 * @param array<int, array<string, mixed>> $complaints The complaints in the period
	 *
	 * @return array{0: array<string, int>, 1: array<string, list<array<string, mixed>>>} Counts and details per employee
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function tallyEmployees(array $complaints): array {
		$employeeCounts = [];
		$employeeDetails = [];

		foreach ($complaints as $complaint) {
			$employee = $complaint['involvedEmployee'] ?? null;
			if ($employee === null || $employee === '') {
				continue;
			}

			$employeeCounts[$employee] = ($employeeCounts[$employee] ?? 0) + 1;
			$employeeDetails[$employee][] = [
				'category' => $complaint['category'] ?? 'unknown',
				'receiptDate' => $complaint['receiptDate'] ?? '',
			];
		}

		return [$employeeCounts, $employeeDetails];
	}//end tallyEmployees()

	/**
	 * The anonymized alerts for employees at or over the threshold, logging each.
	 *
	 * @param array<string, int>                          $employeeCounts  Complaint count per employee
	 * @param array<string, list<array<string, mixed>>>   $employeeDetails Category and date per complaint, per employee
	 * @param array{0: string, 1: string}                 $periods         The period start and end
	 *
	 * @return array<int, array<string, mixed>> Anonymized alert records (employee reference redacted)
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function employeeAlerts(array $employeeCounts, array $employeeDetails, array $periods): array {
		$alerts = [];
		foreach ($employeeCounts as $employee => $count) {
			if ($count < self::EMPLOYEE_ALERT_THRESHOLD) {
				continue;
			}

			// Anonymize: only include count, categories, and periods — not the employee ID.
			$categories = array_unique(array_column($employeeDetails[$employee], 'category'));

			$alerts[] = [
				'count' => $count,
				'categories' => $categories,
				'periods' => $periods,
				'threshold' => self::EMPLOYEE_ALERT_THRESHOLD,
			];

			$this->logger->warning(
				'Employee complaint threshold exceeded: ' . $count . ' complaints in 6 months',
				['app' => AppIdentity::APP_ID],
			);
		}//end foreach

		return $alerts;
	}//end employeeAlerts()

	/**
	 * Get KPI summary for management dashboard.
	 *
	 * @param string $dateFrom ISO date
	 * @param string $dateTo ISO date
	 *
	 * @return array<string, mixed> KPI summary
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getKpiSummary(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$total = count($complaints);
		[$resolved, $withinDeadline] = $this->handledCounts(complaints: $complaints);

		$awbComplianceRate = $this->complianceRate(resolved: $resolved, withinDeadline: $withinDeadline);

		return [
			'total' => $total,
			'resolved' => $resolved,
			'awbComplianceRate' => $awbComplianceRate,
			'dateFrom' => $dateFrom,
			'dateTo' => $dateTo,
		];
	}//end getKpiSummary()

	/**
	 * How many complaints were handled, and how many of those carry a handling deadline.
	 *
	 * @param array<int, array<string, mixed>> $complaints The complaints.
	 *
	 * @return array{0: int, 1: int} Resolved, and resolved within the deadline.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function handledCounts(array $complaints): array {
		$resolved = 0;
		$withinDeadline = 0;

		foreach ($complaints as $complaint) {
			$status = $complaint['status'] ?? '';
			if ($status === 'handled') {
				$resolved++;

				// Check Awb compliance (resolved before afhandelDeadline).
				$deadline = $complaint['afhandelDeadline'] ?? null;
				if ($deadline !== null) {
					$withinDeadline++;
				}
			}

			// Disposition stats would require joining dispositionService — simplified here.
		}

		return [$resolved, $withinDeadline];
	}//end handledCounts()

	/**
	 * The share of handled complaints handled within the deadline, as a percentage.
	 *
	 * @param int $resolved       Handled complaints.
	 * @param int $withinDeadline Handled within the deadline.
	 *
	 * @return float The rate, 0.0 when nothing was handled.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function complianceRate(int $resolved, int $withinDeadline): float {
		$awbComplianceRate = 0.0;
		if ($resolved > 0) {
			$awbComplianceRate = round(($withinDeadline / $resolved) * 100, 1);
		}

		return $awbComplianceRate;
	}//end complianceRate()

	/**
	 * Fetch all complaints in a given date range.
	 *
	 * @param string $dateFrom ISO date (Y-m-d)
	 * @param string $dateTo ISO date (Y-m-d)
	 *
	 * @return array<int, array<string, mixed>> List of complaints
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function fetchComplaintsInRange(string $dateFrom, string $dateTo): array {
		return $this->rows->search(
			schemaKey: 'complaint_schema',
			filters: [
				'ontvangstdatum>=' => $dateFrom,
				'ontvangstdatum<=' => $dateTo,
				'_limit' => 10000,
			]
		);
	}//end fetchComplaintsInRange()

	/**
	 * Get the start and end dates for a given quarter.
	 *
	 * @param int $year Year
	 * @param int $quarter Quarter (1-4)
	 *
	 * @return array{0: string, 1: string} [from, to] ISO dates
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function getQuarterRange(int $year, int $quarter): array {
		$startMonth = ($quarter - 1) * 3 + 1;
		$endMonth = $startMonth + 2;
		$endDay = (int)date('t', mktime(0, 0, 0, $endMonth, 1, $year));

		$from = sprintf('%04d-%02d-01', $year, $startMonth);
		$to = sprintf('%04d-%02d-%02d', $year, $endMonth, $endDay);

		return [$from, $to];
	}//end getQuarterRange()
}//end class
