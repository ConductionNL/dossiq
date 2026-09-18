<?php

/**
 * Dossiq DeadlineReportingController.
 *
 * REST surface for the termijnbewaking reporting endpoints (dashboard
 * KPI, quarterlyReport, annualStatement dwangsommen). Defers all logic to
 * {@see DeadlineReportingService} (ADR-022).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reporting REST surface.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class DeadlineReportingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param DeadlineReportingService $service Reporting service.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ReportingAudience $audience Who may read a figure about every case.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DeadlineReportingService $service,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ReportingAudience $audience,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Refuse a caller who may not read a figure about every case.
	 *
	 * 🔴 THIS USED TO ASK ONLY WHETHER THERE WAS A SESSION, AND THE DOCBLOCK
	 * CALLED IT A "per-object authorization guard" WHEN THERE IS NO OBJECT.
	 * Measured 2026-09-18 by deriving the reporting endpoints from
	 * `appinfo/routes.php` rather than from anyone's list: all three methods
	 * below carried `@NoAdminRequired` and no group check, so
	 * `GET /api/termijn/reports/jaarrekening` -- the ANNUAL DWANGSOM STATEMENT,
	 * what the organisation paid out for missing its own deadlines -- answered
	 * every authenticated account on the instance.
	 *
	 * These three answer AGGREGATES over cases the caller was never granted, so
	 * OpenRegister's per-object refusal never gets a chance to speak. That is
	 * what separates them from an ordinary endpoint and what earns them a gate.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	private function ensureMayReadReports(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		if ($this->audience->mayRead(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudience::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureMayReadReports()

	/**
	 * Dashboard KPI snapshot.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function dashboard(): JSONResponse {
		$denied = $this->ensureMayReadReports();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$row = $this->service->getTermijnKpi();
			return new JSONResponse($row);
		} catch (Throwable $e) {
			$this->logger->error('Termijn dashboard failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end dashboard()

	/**
	 * Quarterly KPI report.
	 *
	 * @param string $period Period (YYYY-Qn).
	 * @param string|null $department Optional department filter.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function quarterlyReport(string $period = '', ?string $department = null): JSONResponse {
		$denied = $this->ensureMayReadReports();
		if ($denied !== null) {
			return $denied;
		}

		if ($period === '') {
			$period = (string)$this->request->getParam('periode', '');
		}

		if ($period === '') {
			return new JSONResponse(['message' => 'periode is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$row = $this->service->generateQuarterlyReport($period, $department);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end quarterlyReport()

	/**
	 * Annual dwangsom audit report.
	 *
	 * @param int $year Year.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function annualStatement(int $year = 0): JSONResponse {
		$denied = $this->ensureMayReadReports();
		if ($denied !== null) {
			return $denied;
		}

		if ($year === 0) {
			$year = (int)$this->request->getParam('jaar', '0');
		}

		if ($year < 2020 || $year > 2100) {
			return new JSONResponse(['message' => 'jaar is required and must be between 2020 and 2100'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$row = $this->service->generateDwangsomAuditReport($year);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end annualStatement()
}//end class
