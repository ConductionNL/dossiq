<?php

/**
 * The two seats on a case, read and named.
 *
 * The People tab already shows every person linked to the case, the
 * coordinator among them, because a coordinator IS a role record. What it
 * cannot show is the pair: which of those people is answerable for the case,
 * beside the handler who is doing it. This endpoint answers exactly that pair,
 * so a panel does not have to know how a generic role maps to an instance's
 * own role type names.
 *
 * 🔴 IT AUTHORISES PER CASE. Naming the coordinator on a case decides who
 * signs the Awb answer, which is the last thing to leave behind an
 * `#[NoAdminRequired]` with no object check.
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads both seats on a case, and names or empties the coordinator.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CaseSeatsController extends Controller {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string                 $appName     The app name.
	 * @param IRequest               $request     The request.
	 * @param CaseSeats              $seats       The handler and the coordinator.
	 * @param CoordinatorRequirement $requirement Whether this case type asks for a coordinator.
	 * @param CaseAccessGuard        $accessGuard Per-case authorization, failing closed.
	 * @param SettingsService        $settings    Bridge to OpenRegister.
	 * @param IUserSession           $userSession The session.
	 * @param LoggerInterface        $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseSeats $seats,
		private readonly CoordinatorRequirement $requirement,
		private readonly CaseAccessGuard $accessGuard,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Both seats on one case, and whether the second one is required.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The seats.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	#[NoAdminRequired]
	public function show(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->notYours();
		}

		$case = $this->caseOf(caseId: $caseId);
		if ($case === []) {
			return new JSONResponse(
				['message' => 'That case could not be read.', 'error' => 'case-unreadable'],
				Http::STATUS_NOT_FOUND,
			);
		}

		$seats = $this->seats->seatsOf(case: $case);
		$seats['coordinatorRequiredBeforeSigning'] = $this->requirement->applies(caseId: $caseId);

		return new JSONResponse($seats);
	}//end show()

	/**
	 * Name the coordinator on a case, or empty the seat.
	 *
	 * An empty `participant` empties the seat, which is how a coordinator is
	 * removed without a second endpoint that does one thing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The seats as they now stand.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	#[NoAdminRequired]
	public function nameCoordinator(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$participant = trim((string)$this->request->getParam('participant', ''));
		$displayName = trim((string)$this->request->getParam('name', ''));

		try {
			if ($participant === '') {
				$this->seats->clearCoordinator(caseId: $caseId);
			} else {
				$this->seats->nameCoordinator(caseId: $caseId, participant: $participant, displayName: $displayName);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseSeatsController: the coordinator seat could not be set',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return new JSONResponse(
				[
					'message' => 'This instance declares no coordinator role type, so the seat cannot be filled. '
						. 'Add one on the Roles tab of the case type.',
					'error' => 'no-coordinator-role-type',
				],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		return $this->show(caseId: $caseId);
	}//end nameCoordinator()

	/**
	 * The caller, when they may write this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null.
	 */
	private function writerOf(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end writerOf()

	/**
	 * The stored case, or an empty array.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function caseOf(string $caseId): array {
		try {
			$objectService = $this->settings->getObjectService();
			if ($objectService === null) {
				return [];
			}

			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $this->settings->getConfigValue('register'),
				schema: $this->settings->getConfigValue('case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseSeatsController: the case could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return [];
		}

		return ($case ?? []);
	}//end caseOf()

	/**
	 * One answer for a case the caller may not touch.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot read the people on this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()
}//end class
