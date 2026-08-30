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

use OCA\Dossiq\Service\ProcessMiningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	 * @param string $appName Nextcloud app id.
	 * @param IRequest $request Incoming request.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager (for RBAC check).
	 * @param ProcessMiningService $processMiningService Report aggregation service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ProcessMiningService $processMiningService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the process-mining bottleneck report.
	 *
	 * @return JSONResponse Report body, 401 when unauthenticated, 403 when
	 *                      the caller lacks the controller/beheerder/admin
	 *                      role, or 400 on invalid parameters.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
	 */
	#[NoAdminRequired]
	public function report(): JSONResponse {
		$denied = $this->ensureAllowed();
		if ($denied !== null) {
			return $denied;
		}

		$from = $this->request->getParam('from');
		$to = $this->request->getParam('to');
		$caseType = $this->request->getParam('caseType');

		$invalid = $this->validateFilters(from: $from, to: $to, caseType: $caseType);
		if ($invalid !== null) {
			return $invalid;
		}

		$params = $this->buildFilters(from: $from, to: $to, caseType: $caseType);

		try {
			return new JSONResponse($this->processMiningService->getReport(params: $params));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: process-mining report generation failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Report generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end report()

	/**
	 * Validate the optional report filter parameters.
	 *
	 * Each filter may be absent (null); when present it must carry the right
	 * shape — `from`/`to` a calendar-valid `Y-m-d` string, `caseType` a string.
	 *
	 * @param mixed $from Raw `from` request parameter.
	 * @param mixed $to Raw `to` request parameter.
	 * @param mixed $caseType Raw `caseType` request parameter.
	 *
	 * @return JSONResponse|null A 400 response for the first offending filter, or null when all are acceptable.
	 */
	private function validateFilters(mixed $from, mixed $to, mixed $caseType): ?JSONResponse {
		if ($from !== null && (is_string($from) === false || $this->isValidDate(value: $from) === false)) {
			return new JSONResponse(['message' => 'from must be a Y-m-d date'], Http::STATUS_BAD_REQUEST);
		}

		if ($to !== null && (is_string($to) === false || $this->isValidDate(value: $to) === false)) {
			return new JSONResponse(['message' => 'to must be a Y-m-d date'], Http::STATUS_BAD_REQUEST);
		}

		if ($caseType !== null && is_string($caseType) === false) {
			return new JSONResponse(['message' => 'caseType must be a string'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end validateFilters()

	/**
	 * Assemble the service filter map from the validated request parameters.
	 *
	 * Absent and empty-string filters are omitted so the service sees only the
	 * filters the caller actually supplied.
	 *
	 * @param mixed $from Validated `from` request parameter.
	 * @param mixed $to Validated `to` request parameter.
	 * @param mixed $caseType Validated `caseType` request parameter.
	 *
	 * @return array<string, string> The filter map passed to the report service.
	 */
	private function buildFilters(mixed $from, mixed $to, mixed $caseType): array {
		$params = [];
		if (is_string($from) === true && $from !== '') {
			$params['from'] = $from;
		}

		if (is_string($to) === true && $to !== '') {
			$params['to'] = $to;
		}

		if (is_string($caseType) === true && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		return $params;
	}//end buildFilters()

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

	/**
	 * Validate a `Y-m-d` date string.
	 *
	 * Accepts only a zero-padded calendar date: the shape is checked first,
	 * then the calendar validity (so `2026-02-30` is rejected rather than
	 * silently rolled over to March).
	 *
	 * @param string $value Candidate date string.
	 *
	 * @return bool
	 */
	private function isValidDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isValidDate()
}//end class
