<?php

/**
 * Procest ConsultationAccessGuard.
 *
 * Authorization and request-decoding collaborator for the consultation
 * (adviesaanvraag) endpoints. Split out of ConsultationController so that
 * controller keeps only endpoint shape: the rules for who may see or mutate a
 * consultation — authenticated, consultation exists, and the caller is the
 * aanvrager, the assignee or an administrator (OWASP A01:2021, ADR-005 Rule 3)
 * — live here and nowhere else.
 *
 * @category Service
 * @package  OCA\Procest\Service\Consultation
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Consultation;

use OCA\Procest\Service\ConsultationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Resolves and authorizes consultation access for the controller layer.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class ConsultationAccessGuard {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request, for body decoding.
	 * @param ConsultationService $consultationService The consultation service.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager, for the admin bypass.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ConsultationService $consultationService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Reject an unauthenticated caller.
	 *
	 * @return JSONResponse|null Null when a user is signed in, a 401 response otherwise.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * The signed-in user's UID, or an empty string when there is no session.
	 *
	 * @return string The current UID.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function currentUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * Authenticate the caller, load the consultation and authorize access.
	 *
	 * A user is authorized when they are the aanvrager (original requestor),
	 * the assignee (individual handler), or an administrator.
	 *
	 * @param string $consultationId The consultation UUID.
	 *
	 * @return ConsultationAccess The denial response, or the resolved consultation.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function authorize(string $consultationId): ConsultationAccess {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new ConsultationAccess(
				error: new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED),
			);
		}

		$consultation = $this->consultationService->getConsultation(consultationId: $consultationId);
		if ($consultation === null) {
			return new ConsultationAccess(
				error: new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND),
			);
		}

		if ($this->isPermitted(consultation: $consultation, uid: $user->getUID()) === false) {
			return new ConsultationAccess(
				error: new JSONResponse(
					['error' => 'Access to this consultation is not permitted'],
					Http::STATUS_FORBIDDEN,
				),
			);
		}

		return new ConsultationAccess(error: null, consultation: $consultation);
	}//end authorize()

	/**
	 * Whether the user may act on this consultation.
	 *
	 * @param array<string, mixed> $consultation The consultation data.
	 * @param string $uid The user's UID.
	 *
	 * @return bool True when the user is the aanvrager, the assignee or an admin.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	private function isPermitted(array $consultation, string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		$aanvrager = $consultation['aanvrager'] ?? '';
		$assignee = $consultation['assignee'] ?? '';

		return ($uid === $aanvrager || ($assignee !== '' && $uid === $assignee));
	}//end isPermitted()

	/**
	 * Reject a create payload whose dependsOn list would form a cycle.
	 *
	 * @param array<string, mixed> $data The decoded create payload.
	 *
	 * @return JSONResponse|null A 400 response on a cycle, null otherwise.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function dependencyCycleError(array $data): ?JSONResponse {
		$dependsOn = $data['dependsOn'] ?? [];
		if (is_array($dependsOn) === false || empty($dependsOn) === true) {
			return null;
		}

		if ($this->consultationService->validateDependencyCycle(
			consultationId: '',
			dependsOn: $dependsOn,
		) === false
		) {
			return null;
		}

		return new JSONResponse(
			['error' => 'Dependency cycle detected in dependsOn list'],
			Http::STATUS_BAD_REQUEST,
		);
	}//end dependencyCycleError()

	/**
	 * Parse the request body as JSON and return it as an array.
	 *
	 * @return array<string, mixed> The decoded body, or an empty array.
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function requestBody(): array {
		$content = $this->request->getContent();
		if ($content === '' || $content === false) {
			$content = '{}';
		}

		$decoded = json_decode((string)$content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end requestBody()
}//end class
