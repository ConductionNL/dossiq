<?php

/**
 * Dossiq Complaint Analytics Controller
 *
 * Read-only reporting surface over complaint (klacht) data: frequency
 * breakdowns, monthly trend, average resolution time, employee threshold
 * alerts, and the management KPI summary.
 *
 * Split out of ComplaintController along the sub-domain seam — these endpoints
 * aggregate across all complaints rather than acting on one, depend only on
 * ComplaintAnalyticsService, and need no per-object authorization guard.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\Complaint\EmployeeAlertAudience;
use OCA\Dossiq\Service\ComplaintAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for complaint analytics and KPIs.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintAnalyticsController extends Controller {
	/**
	 * Response key for each complaint dimension whose frequency is reported.
	 *
	 * @var array<string, string>
	 */
	private const FREQUENCY_DIMENSIONS = [
		'byCategorie' => 'category',
		'byAfdeling' => 'involvedDepartment',
		'byKanaal' => 'receiptChannel',
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param ComplaintAnalyticsService $analyticsService Analytics service
	 * @param ComplaintAccessGuard $accessGuard Shared complaint authorization guard
	 * @param EmployeeAlertAudience $alertAudience Who may read the employee threshold alerts
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ComplaintAnalyticsService $analyticsService,
		private readonly ComplaintAccessGuard $accessGuard,
		private readonly EmployeeAlertAudience $alertAudience,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get complaint frequency analytics.
	 *
	 * The employee threshold alerts are only built and returned for the alert
	 * audience (EmployeeAlertAudience); every other caller gets the frequency
	 * figures without the `employeeAlerts` key.
	 *
	 * @return JSONResponse Analytics data
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function analytics(): JSONResponse {
		$uid = $this->accessGuard->currentUid();
		if ($uid === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$mayReadAlerts = $this->alertAudience->mayReadEmployeeAlerts($uid);

		$body = $this->frequencyFigures(
			dateFrom: $this->request->getParam('dateFrom') ?? date('Y-01-01'),
			dateTo: $this->request->getParam('dateTo') ?? date('Y-m-d'),
		);
		if ($mayReadAlerts === true) {
			$body['employeeAlerts'] = $this->analyticsService->checkEmployeeThresholdAlerts();
		}

		return new JSONResponse($body);
	}//end analytics()

	/**
	 * The complaint frequency figures for one window.
	 *
	 * @param string $dateFrom First day of the window (Y-m-d).
	 * @param string $dateTo   Last day of the window (Y-m-d).
	 *
	 * @return array<string, mixed> Frequency per dimension, the monthly trend and the average resolution.
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	private function frequencyFigures(string $dateFrom, string $dateTo): array {
		$figures = [];
		foreach (self::FREQUENCY_DIMENSIONS as $key => $dimension) {
			$figures[$key] = $this->analyticsService->getFrequencyByDimension(
				dimension: $dimension,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
			);
		}

		$figures['monthlyTrend'] = $this->analyticsService->getMonthlyTrend(dateFrom: $dateFrom, dateTo: $dateTo);
		$figures['avgResolution'] = $this->analyticsService->getAverageResolutionTime(dateFrom: $dateFrom, dateTo: $dateTo);
		return $figures;
	}//end frequencyFigures()

	/**
	 * Get KPI cards for management dashboard.
	 *
	 * @return JSONResponse KPI data
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function kpi(): JSONResponse {
		if ($this->accessGuard->currentUid() === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$dateFrom = $this->request->getParam('dateFrom') ?? date('Y-m-01');
		$dateTo = $this->request->getParam('dateTo') ?? date('Y-m-d');

		$kpi = $this->analyticsService->getKpiSummary($dateFrom, $dateTo);
		return new JSONResponse($kpi);
	}//end kpi()
}//end class
