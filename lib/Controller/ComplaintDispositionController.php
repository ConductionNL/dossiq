<?php

/**
 * Procest Complaint Disposition Controller
 *
 * REST API for the afdoening (disposition) step of complaint handling per Awb
 * chapter 9: reading a complaint's disposition, submitting one (optionally for
 * coordinator approval), approving it, and generating the formal response
 * letter.
 *
 * Split out of ComplaintController along the sub-domain seam — dispositions are
 * a distinct workflow step with their own service (DispositionService) and
 * their own `/api/complaints/{id}/disposition` URL group. Submission and
 * approval also close out the parent complaint, so ComplaintService stays
 * injected.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\DispositionService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for complaint dispositions (afdoeningen).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintDispositionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param ComplaintService $complaintService Complaint service
	 * @param DispositionService $dispositionService Disposition service
	 * @param SettingsService $settingsService Settings service
	 * @param ComplaintAccessGuard $accessGuard Shared complaint authorization guard
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ComplaintService $complaintService,
		private readonly DispositionService $dispositionService,
		private readonly SettingsService $settingsService,
		private readonly ComplaintAccessGuard $accessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get the disposition for a complaint.
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return JSONResponse Disposition or 404
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function getDisposition(string $id): JSONResponse {
		if ($this->accessGuard->currentUid() === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$disposition = $this->dispositionService->getDispositionForComplaint($id);
		if ($disposition === null) {
			return new JSONResponse(['error' => 'No disposition found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($disposition);
	}//end getDisposition()

	/**
	 * Submit a disposition for a complaint.
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return JSONResponse Created disposition
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function submitDisposition(string $id): JSONResponse {
		$userId = $this->accessGuard->currentUid();
		if ($userId === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$complaint = $this->complaintService->getComplaint($id);
		if ($complaint === null) {
			return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
		}

		$this->accessGuard->authorizeMutation(complaint: $complaint, userId: $userId);

		try {
			$data = $this->accessGuard->parseBody();
			$approvalSetting = $this->settingsService->getConfigValue('complaint_require_approval');
			$requireApproval = in_array(strtolower($approvalSetting), ['1', 'true', 'yes'], true);

			if ($requireApproval === true) {
				$disposition = $this->dispositionService->submitDispositionForApproval($id, $data);
				return new JSONResponse($disposition, Http::STATUS_CREATED);
			}

			$disposition = $this->dispositionService->submitDisposition($id, $data);
			$this->complaintService->transitionStatus($id, 'afgehandeld');

			return new JSONResponse($disposition, Http::STATUS_CREATED);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end submitDisposition()

	/**
	 * Approve a disposition (coordinator endpoint).
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return JSONResponse Updated disposition
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function approveDisposition(string $id): JSONResponse {
		$userId = $this->accessGuard->currentUid();
		if ($userId === '') {
			return $this->accessGuard->notAuthenticated();
		}

		// Only coordinators (admins) may approve.
		$this->accessGuard->requireCoordinator(userId: $userId);

		try {
			$disposition = $this->dispositionService->getDispositionForComplaint($id);
			if ($disposition === null) {
				return new JSONResponse(['error' => 'No disposition found for complaint'], Http::STATUS_NOT_FOUND);
			}

			$dispositionId = $disposition['id'] ?? $disposition['uuid'] ?? '';
			$result = $this->dispositionService->approveDisposition($dispositionId, $userId);
			$this->complaintService->transitionStatus($id, 'afgehandeld');
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end approveDisposition()

	/**
	 * Generate a formal response letter for a complaint.
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return JSONResponse Letter generation result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function generateLetter(string $id): JSONResponse {
		$userId = $this->accessGuard->currentUid();
		if ($userId === '') {
			return $this->accessGuard->notAuthenticated();
		}

		$complaint = $this->complaintService->getComplaint($id);
		if ($complaint === null) {
			return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
		}

		$this->accessGuard->authorizeMutation(complaint: $complaint, userId: $userId);

		$disposition = $this->dispositionService->getDispositionForComplaint($id);
		if ($disposition === null) {
			return new JSONResponse(['error' => 'Submit disposition before generating a letter'], Http::STATUS_BAD_REQUEST);
		}

		$dispositionId = $disposition['id'] ?? $disposition['uuid'] ?? '';
		$result = $this->dispositionService->generateResponseLetter($id, $dispositionId);
		return new JSONResponse($result);
	}//end generateLetter()
}//end class
