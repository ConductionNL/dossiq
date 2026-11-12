<?php

/**
 * Dossiq complaint mutation gate.
 *
 * The refusals every complaint write answers before it touches anything: a
 * caller without a session (401), a complaint that does not exist (404), and
 * a caller who may not change it (the access guard's own refusal).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\ComplaintService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Refuses a complaint write the caller may not make.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
 */
final class ComplaintMutationGate {

	/**
	 * Constructor.
	 *
	 * @param ComplaintAccessGuard $accessGuard      Shared complaint authorization guard.
	 * @param ComplaintService     $complaintService Reads the complaint.
	 */
	public function __construct(
		private readonly ComplaintAccessGuard $accessGuard,
		private readonly ComplaintService $complaintService,
	) {
	}//end __construct()

	/**
	 * The refusal of a write to this complaint, or null when the caller may make it.
	 *
	 * The session is checked first, then the complaint is read, then the
	 * caller's right to change it is asserted; the guard throws its own
	 * refusal for a caller who may not.
	 *
	 * @param string $id Complaint UUID.
	 *
	 * @return JSONResponse|null The 401 or 404, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function refusal(string $id): ?JSONResponse {
		$userId = $this->accessGuard->currentUid();
		if ($userId === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$complaint = $this->complaintService->getComplaint($id);
		if ($complaint === null) {
			return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
		}

		$this->accessGuard->authorizeMutation(complaint: $complaint, userId: $userId);

		return null;
	}//end refusal()
}//end class
