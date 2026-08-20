<?php

/**
 * Procest DeadlineReportingController.
 *
 * REST surface for the termijnbewaking reporting endpoints (dashboard
 * KPI, quarterlyReport, annualStatement dwangsommen). Defers all logic to
 * {@see DeadlineReportingService} (ADR-022).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\DeadlineReportingService;
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
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DeadlineReportingService $service,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

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
		$denied = $this->ensureAuthenticated();
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
		$denied = $this->ensureAuthenticated();
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
		$denied = $this->ensureAuthenticated();
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
