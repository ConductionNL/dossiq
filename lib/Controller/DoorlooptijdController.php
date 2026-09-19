<?php

/**
 * Dossiq Doorlooptijd (throughput-time) Controller
 *
 * Thin REST entry-point for the throughput-time dashboard. Reads query
 * parameters, validates types, delegates to {@see DoorlooptijdService}.
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
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the throughput-time dashboard.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */
class DoorlooptijdController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param DoorlooptijdService $leadTimeService Metrics service.
	 * @param IUserSession $userSession Current user session.
	 * @param ReportingAudience $audience Who may read a figure about every case.
	 */
	public function __construct(
		IRequest $request,
		private readonly DoorlooptijdService $leadTimeService,
		private readonly IUserSession $userSession,
		private readonly ReportingAudience $audience,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the metrics payload.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse Metrics body or 400 on invalid parameters.
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
	 */
	public function metrics(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// 🔴 MEASURED 2026-09-18: `@NoAdminRequired` and no group check, so every
		// authenticated account read the organisation's processing times, its
		// cases at risk and its performance per case type. It is an AGGREGATE
		// over cases the caller was never granted, so OpenRegister's per-object
		// refusal never gets a chance to speak.
		if ($this->audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudience::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		$caseType = $this->request->getParam('caseType');
		$period = $this->request->getParam('period', '12m');
		$atRiskRaw = $this->request->getParam('atRiskDays', 5);

		$refusal = $this->badRequest(caseType: $caseType, period: $period, atRiskRaw: $atRiskRaw);
		if ($refusal !== null) {
			return $refusal;
		}

		$params = [
			'period' => $period,
			'atRiskDays' => (int)$atRiskRaw,
		];
		if (is_string($caseType) === true && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		return new JSONResponse($this->leadTimeService->getMetrics(params: $params));
	}//end metrics()

	/**
	 * The refusal a malformed query earns, or null when it reads.
	 *
	 * @param mixed $caseType  The case type filter as it arrived.
	 * @param mixed $period    The period as it arrived.
	 * @param mixed $atRiskRaw The at-risk window as it arrived.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/specs/reporting-and-metrics/spec.md
	 */
	private function badRequest(mixed $caseType, mixed $period, mixed $atRiskRaw): ?JSONResponse {
		if ($caseType !== null && is_string($caseType) === false) {
			return new JSONResponse(['message' => 'caseType must be a string'], Http::STATUS_BAD_REQUEST);
		}

		if (is_string($period) === false || preg_match('/^\d+m$/', $period) !== 1) {
			return new JSONResponse(['message' => 'period must look like 12m'], Http::STATUS_BAD_REQUEST);
		}

		if (is_numeric($atRiskRaw) === false) {
			return new JSONResponse(['message' => 'atRiskDays must be a number'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end badRequest()
}//end class
