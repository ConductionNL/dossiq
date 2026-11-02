<?php

/**
 * Moving a committee advice request through its lifecycle.
 *
 * AdvisoryCommitteeService::transitionAdviceStatus() checks each move and is
 * the only writer DeliberationStartGuard lets into advice-issued or
 * inadmissible. This endpoint is how the advice request page reaches it.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-the-committee-moves-an-advice-request-through-its-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The advisory committee's own move of an advice request.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-the-committee-moves-an-advice-request-through-its-own-endpoint
 */
class BezwaarAdviceRequestController extends Controller {

	/**
	 * The group of the bezwaaradviescommissie; provisioned by ProvisionAssignedGroups.
	 *
	 * Awb art. 7:13 keeps the committee independent of the officials who
	 * handled the case, so the case's handlers are not admitted.
	 */
	public const COMMITTEE_GROUP = 'bezwaarcommissie';

	/**
	 * The advice fields a transition may carry; anything else in the body is ignored.
	 */
	private const ADVICE_FIELDS = [
		'advice',
		'adviceDocuments',
		'signatureEvidence',
		'hearingReportRef',
		'conclusion',
		'recommendation',
		'dissentingOpinions',
	];

	/**
	 * Constructor.
	 *
	 * @param string                   $appName           The app name.
	 * @param IRequest                 $request           The request.
	 * @param AdvisoryCommitteeService $advisoryCommittee The advice request lifecycle.
	 * @param IUserSession             $userSession       The session.
	 * @param IGroupManager            $groupManager      Whether the caller is in the committee or an administrator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AdvisoryCommitteeService $advisoryCommittee,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Move one advice request to a new status, with the advice fields the move needs.
	 *
	 * @param string $id UUID of the advice request.
	 *
	 * @return JSONResponse The updated advice request as OpenRegister serialises it, or the refusal.
	 *
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-the-committee-moves-an-advice-request-through-its-own-endpoint
	 */
	#[NoAdminRequired]
	public function transition(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isInGroup($uid, self::COMMITTEE_GROUP) === false
			&& $this->groupManager->isAdmin($uid) === false
		) {
			return new JSONResponse(['error' => 'Only the advisory committee can move an advice request'], Http::STATUS_FORBIDDEN);
		}

		$status = $this->request->getParam('status');
		if (is_string($status) === false || $status === '') {
			return new JSONResponse(['error' => 'Name the status to move to.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->run(requestId: $id, move: ['status' => $status] + $this->adviceFields());
	}//end transition()

	/**
	 * Run the transition and answer its outcome.
	 *
	 * @param string               $requestId UUID of the advice request.
	 * @param array<string, mixed> $move      The target status and the advice fields.
	 *
	 * @return JSONResponse The updated advice request, or the refusal.
	 */
	private function run(string $requestId, array $move): JSONResponse {
		$status = (string) $move['status'];
		unset($move['status']);

		try {
			return new JSONResponse(
				$this->advisoryCommittee->transitionAdviceStatus(requestId: $requestId, newStatus: $status, payload: $move)
			);
		} catch (RuntimeException $refused) {
			$code = Http::STATUS_BAD_REQUEST;
			if ($refused->getCode() === AdvisoryCommitteeService::NOT_FOUND) {
				$code = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $refused->getMessage()], $code);
		}
	}//end run()

	/**
	 * The advice fields in the request body.
	 *
	 * @return array<string, mixed> The fields, keyed by name.
	 */
	private function adviceFields(): array {
		$fields = [];
		foreach (self::ADVICE_FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$fields[$field] = $value;
			}
		}

		return $fields;
	}//end adviceFields()
}//end class
