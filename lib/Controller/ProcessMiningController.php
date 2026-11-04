<?php

/**
 * Dossiq Process Mining Controller
 *
 * REST surface for the process-mining bottleneck report:
 * `GET /api/reports/process-mining`. Same gate shape as
 * the retired IV3 report — process mining spans every case in the tenant,
 * not just the caller's own work, so access is restricted to the
 * controller/beheerder/admin roles rather than any authenticated user.
 * Defers all logic to {@see ProcessMiningService} (ADR-022).
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\ProcessMining\ReportAnswer;
use OCA\Dossiq\Service\ProcessMining\ReportRequest;
use OCA\Dossiq\Service\ProcessMiningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Process-mining bottleneck REST surface.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
 *
 * @psalm-suppress UnusedClass
 */
class ProcessMiningController extends Controller {
	/**
	 * Groups that may read the process-mining report — same gate shape as
	 * the same allowed-group shape the retired IV3 report used: it spans the
	 * whole case population, not just the caller's own work.
	 */
	private const ALLOWED_GROUPS = ['controllers', 'beheerders', 'admin'];

	/**
	 * Constructor.
	 *
	 * @param string               $appName              Nextcloud app id.
	 * @param IRequest             $request              Incoming request.
	 * @param IUserSession         $userSession          Current user session.
	 * @param IGroupManager        $groupManager         Group manager (for RBAC check).
	 * @param ProcessMiningService $processMiningService Report aggregation service.
	 * @param LoggerInterface      $logger               PSR-3 logger.
	 * @param ReportRequest        $requests             Checks the query and builds the filters.
	 * @param ReportAnswer         $answers              Shapes the JSON body and the CSV.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ProcessMiningService $processMiningService,
		private readonly LoggerInterface $logger,
		private readonly ReportRequest $requests,
		private readonly ReportAnswer $answers,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the process-mining bottleneck report, or with `format=csv` the
	 * days per phase per case type as a download.
	 *
	 * `period` (a {@see \OCA\Dossiq\Service\Reporting\ReportPeriod} preset)
	 * sets `from` when `from` is not given. Without `view` the whole report is
	 * answered with every display block of {@see \OCA\Dossiq\Service\ProcessMining\ReportViews}
	 * beside it; `view` narrows the answer to that block plus `period`,
	 * `clock`, `labels` and `truncated`.
	 *
	 * @return JSONResponse|DataDownloadResponse Report body, the CSV, 401 when
	 *                                          unauthenticated, 403 when the caller lacks the
	 *                                          controller/beheerder/admin role, or 400 on
	 *                                          invalid parameters.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
	 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
	 */
	#[NoAdminRequired]
	public function report(): JSONResponse|DataDownloadResponse {
		$denied = $this->ensureAllowed();
		if ($denied !== null) {
			return $denied;
		}

		$query = $this->query();
		$refusal = $this->requests->refusal(query: $query);
		if ($refusal !== null) {
			return new JSONResponse(['message' => $refusal], Http::STATUS_BAD_REQUEST);
		}

		$params = $this->requests->filters(query: $query);

		try {
			$report = $this->processMiningService->getReport(params: $params);
			if ($query['format'] === 'csv') {
				return new DataDownloadResponse(
					$this->answers->csv(report: $report, statistic: $query['statistic']),
					'procesanalyse.csv',
					'text/csv; charset=utf-8'
				);
			}

			return new JSONResponse($this->answers->json(report: $report, view: $query['view']));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: process-mining report generation failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Report generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end report()

	/**
	 * The report's query parameters; the download is JSON of the mean when the request names none.
	 *
	 * @return array<string, mixed> The parameters by name.
	 */
	private function query(): array {
		$query = [];
		foreach (['from', 'to', 'caseType', 'period', 'view'] as $name) {
			$query[$name] = $this->request->getParam($name);
		}

		$query['format'] = ($this->request->getParam('format') ?? 'json');
		$query['statistic'] = ($this->request->getParam('statistic') ?? 'mean');

		return $query;
	}//end query()

	/**
	 * Authentication + RBAC guard for {@see self::report()}.
	 *
	 * @return JSONResponse|null Null when the caller is allowed.
	 */
	private function ensureAllowed(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->isAllowed(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['message' => 'Process-mining report access requires the controller/beheerder role'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end ensureAllowed()

	/**
	 * Check whether the given user id belongs to an allowed group (or is an
	 * NC admin, defensive default) — same shape as
	 * the retired IV3 report's isAllowed().
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return bool
	 */
	private function isAllowed(string $uid): bool {
		foreach (self::ALLOWED_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return $this->groupManager->isAdmin($uid) === true;
	}//end isAllowed()
}//end class
