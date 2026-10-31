<?php

/**
 * The status a case moves to next (DqTaak "Wat deze taak tegenhoudt").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Cases\CaseNextStatus;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * `GET /api/cases/{caseId}/next-status`: `{name, number}`, `{}` at the last status, 404 for a case the user may not read.
 *
 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
 */
class CaseNextStatusController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string         $appName    The app name.
	 * @param IRequest       $request    The request.
	 * @param CaseNextStatus $nextStatus Reads the next status.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseNextStatus $nextStatus,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The case's next status.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
	 */
	#[NoAdminRequired]
	public function show(string $caseId): JSONResponse {
		try {
			$next = $this->nextStatus->forCase(caseId: trim($caseId));
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The case could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($next === null) {
			return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse((object)$next);
	}//end show()
}//end class
