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
use OCA\Dossiq\Service\Term\FirstResponseOutcome;
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
		private readonly ?FirstResponseOutcome $firstResponse = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * How the first response is doing, per case type or per organisation.
	 *
	 * The count and the average come out of the numbers stored on the cases,
	 * not out of dates recomputed now: a case decided a year ago was late by
	 * what it was late by, whatever the configuration says today.
	 *
	 * @param string $caseType     Optional case type filter.
	 * @param string $organisation Optional organisation filter.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The report.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
	 */
	public function firstResponseReport(string $caseType = '', string $organisation = ''): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		if ($this->firstResponse === null) {
			return new JSONResponse(['message' => 'Not available'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$filters = [];
		if ($caseType !== '') {
			$filters['caseType'] = $caseType;
		}

		if ($organisation !== '') {
			$filters['competentAuthority'] = $organisation;
		}

		try {
			return new JSONResponse($this->firstResponse->report(filters: $filters));
		} catch (Throwable $e) {
			$this->logger->error('First-response report failed', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end firstResponseReport()

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
