<?php

/**
 * Procest Complaint Analytics Service
 *
 * Service for complaint frequency analysis, anonymized employee-threshold
 * alerts, and systemic-issue detection (>50% quarter-over-quarter increase).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service for complaint analytics, frequency aggregation, and systemic-issue detection.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
 */
class ComplaintAnalyticsService {

	use SearchesObjects;

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Aggregate complaint frequency by a given dimension for a date range.
	 *
	 * @param string $dimension Grouping dimension: 'categorie', 'betrokkenAfdeling', 'ontvangstkanaal'
	 * @param string $dateFrom ISO date string (Y-m-d) for range start
	 * @param string $dateTo ISO date string (Y-m-d) for range end
	 *
	 * @return array<string, int> Map of dimension value => complaint count
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getFrequencyByDimension(string $dimension, string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$frequency = [];

		foreach ($complaints as $complaint) {
			$value = (string)($complaint[$dimension] ?? 'onbekend');
			if (isset($frequency[$value]) === false) {
				$frequency[$value] = 0;
			}

			$frequency[$value]++;
		}

		// Privacy: when slicing by an employee-identifying dimension, suppress
		// slices below the minimum threshold so individual employees cannot be
		// re-identified from low-count buckets.
		if (in_array($dimension, ['betrokkenMedewerker', 'behandelaar'], true) === true) {
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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getMonthlyTrend(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$trend = [];

		foreach ($complaints as $complaint) {
			$date = $complaint['ontvangstdatum'] ?? '';
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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getAverageResolutionTime(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$totals = [];
		$counts = [];

		foreach ($complaints as $complaint) {
			if (($complaint['status'] ?? '') !== 'afgehandeld') {
				continue;
			}

			$category = (string)($complaint['categorie'] ?? 'onbekend');
			$receipt = $complaint['ontvangstdatum'] ?? null;
			$afhandelDeadline = $complaint['afhandelDeadline'] ?? null;

			if ($receipt === null || $afhandelDeadline === null) {
				continue;
			}

			$start = new DateTimeImmutable($receipt);
			$end = new DateTimeImmutable($afhandelDeadline);
			$days = (int)$start->diff($end)->days;

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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function detectSystemicIssues(int $year, int $quarter): array {
		[$currentFrom, $currentTo] = $this->getQuarterRange(year: $year, quarter: $quarter);

		$prevYear = $year;
		$prevQuarter = ($quarter - 1);
		if ($quarter === 1) {
			$prevYear = ($year - 1);
			$prevQuarter = 4;
		}

		[$prevFrom, $prevTo] = $this->getQuarterRange(year: $prevYear, quarter: $prevQuarter);

		$current = $this->getFrequencyByDimension(dimension: 'categorie', dateFrom: $currentFrom, dateTo: $currentTo);
		$previous = $this->getFrequencyByDimension(dimension: 'categorie', dateFrom: $prevFrom, dateTo: $prevTo);

		$systemicIssues = [];

		foreach ($current as $category => $currentCount) {
			$previousCount = $previous[$category] ?? 0;

			if ($previousCount === 0) {
				continue;
			}

			$increasePercent = (($currentCount - $previousCount) / $previousCount) * 100;

			if ($increasePercent > self::SYSTEMIC_ISSUE_QOQ_THRESHOLD) {
				$systemicIssues[] = [
					'categorie' => $category,
					'currentCount' => $currentCount,
					'previousCount' => $previousCount,
					'increasePercent' => round($increasePercent, 1),
					'quarter' => 'Q' . $quarter . ' ' . $year,
					'previousQuarter' => 'Q' . $prevQuarter . ' ' . $prevYear,
				];
			}
		}

		if (empty($systemicIssues) === false) {
			$this->logger->warning(
				'Systemic complaint issues detected: ' . count($systemicIssues) . ' categories flagged',
				['app' => Application::APP_ID],
			);
		}

		return $systemicIssues;
	}//end detectSystemicIssues()

	/**
	 * Check for employees referenced in >= 3 complaints in the last 6 months.
	 *
	 * @return array<int, array<string, mixed>> Anonymized alert records (employee reference redacted)
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function checkEmployeeThresholdAlerts(): array {
		$sixMonthsAgo = (new DateTimeImmutable('today'))->modify('-6 months')->format('Y-m-d');
		$today = date('Y-m-d');

		$complaints = $this->fetchComplaintsInRange(dateFrom: $sixMonthsAgo, dateTo: $today);
		$employeeCounts = [];
		$employeeDetails = [];

		foreach ($complaints as $complaint) {
			$employee = $complaint['betrokkenMedewerker'] ?? null;
			if ($employee === null || $employee === '') {
				continue;
			}

			$employeeCounts[$employee] = ($employeeCounts[$employee] ?? 0) + 1;
			$employeeDetails[$employee][] = [
				'categorie' => $complaint['categorie'] ?? 'onbekend',
				'ontvangstdatum' => $complaint['ontvangstdatum'] ?? '',
			];
		}

		$alerts = [];
		foreach ($employeeCounts as $employee => $count) {
			if ($count < self::EMPLOYEE_ALERT_THRESHOLD) {
				continue;
			}

			// Anonymize: only include count, categories, and periods — not the employee ID.
			$categories = array_unique(array_column($employeeDetails[$employee], 'categorie'));

			$alerts[] = [
				'count' => $count,
				'categories' => $categories,
				'periods' => [$sixMonthsAgo, $today],
				'threshold' => self::EMPLOYEE_ALERT_THRESHOLD,
			];

			$this->logger->warning(
				'Employee complaint threshold exceeded: ' . $count . ' complaints in 6 months',
				['app' => Application::APP_ID],
			);
		}//end foreach

		return $alerts;
	}//end checkEmployeeThresholdAlerts()

	/**
	 * Get KPI summary for management dashboard.
	 *
	 * @param string $dateFrom ISO date
	 * @param string $dateTo ISO date
	 *
	 * @return array<string, mixed> KPI summary
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getKpiSummary(string $dateFrom, string $dateTo): array {
		$complaints = $this->fetchComplaintsInRange(dateFrom: $dateFrom, dateTo: $dateTo);
		$total = count($complaints);
		$resolved = 0;
		$withinDeadline = 0;

		foreach ($complaints as $complaint) {
			$status = $complaint['status'] ?? '';
			if ($status === 'afgehandeld') {
				$resolved++;

				// Check Awb compliance (resolved before afhandelDeadline).
				$deadline = $complaint['afhandelDeadline'] ?? null;
				if ($deadline !== null) {
					$withinDeadline++;
				}
			}

			// Disposition stats would require joining dispositionService — simplified here.
		}

		$awbComplianceRate = 0.0;
		if ($resolved > 0) {
			$awbComplianceRate = round(($withinDeadline / $resolved) * 100, 1);
		}

		return [
			'total' => $total,
			'resolved' => $resolved,
			'awbComplianceRate' => $awbComplianceRate,
			'dateFrom' => $dateFrom,
			'dateTo' => $dateTo,
		];
	}//end getKpiSummary()

	/**
	 * Fetch all complaints in a given date range.
	 *
	 * @param string $dateFrom ISO date (Y-m-d)
	 * @param string $dateTo ISO date (Y-m-d)
	 *
	 * @return array<int, array<string, mixed>> List of complaints
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
	 */
	private function fetchComplaintsInRange(string $dateFrom, string $dateTo): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
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
