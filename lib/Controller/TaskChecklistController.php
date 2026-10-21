<?php

/**
 * A checklist run on a case, as a task
 *
 * Submits one filled-in checklist for a case and reads the runs a case has.
 * A run is an OpenRegister task (see
 * {@see \OCA\Dossiq\Service\Inspection\InspectionRunService}) at
 * `/api/cases/{id}/checklist-results`. The earlier
 * `/api/vth/cases/{id}/inspection-result(s)` stays routed to the
 * `*LegacyPath()` delegates, because the offline client replays to it.
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
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Submits and reads the checklist runs of one case.
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class TaskChecklistController extends Controller {
	/**
	 * Constructor.
	 *
	 * `IGroupManager` is deliberately NOT injected (#799). The only thing it
	 * did here was the admin bypass in front of a guard that could never
	 * refuse, and `CaseAccessGuard` performs that same bypass itself, in one
	 * place, next to the per-case check it belongs to.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 * @param InspectionRunService $runs Checklist runs as OpenRegister tasks
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly InspectionRunService $runs,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Submit one filled-in checklist for a case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * WHAT THIS REPLACED, AND WHY THE REPLACEMENT IS A DIFFERENT KIND OF THING
	 * (#799). The previous guard read:
	 *
	 *     $params = $this->request->getParams();
	 *     if ($this->groupManager->isAdmin($user->getUID()) === false) {
	 *         $assignedUid = $params['assignedInspector'] ?? '';
	 *         if ($assignedUid !== '' && $assignedUid !== $user->getUID()) {
	 *             throw new OCSForbiddenException('Not authorized ...');
	 *         }
	 *     }
	 *
	 * `$params` is the REQUEST BODY, so the value the guard compared the
	 * caller against was supplied by the caller it was meant to constrain.
	 * There was no input for which it threw: omit `assignedInspector` and
	 * `$assignedUid` is `''`, so the `!== ''` conjunct is false and the branch
	 * is skipped; send your own uid and the second conjunct is false too.
	 * `assignedInspector` is not a property of the `inspectionResult` schema
	 * either, so nothing server-side was ever going to be compared against it.
	 *
	 * The replacement asks a question the caller cannot answer: the case is
	 * READ back through OpenRegister and the acting uid is compared against
	 * the stored `assignee`. Admin still bypasses, as it does on every other
	 * per-case surface in this app; everything else denies, including an
	 * absent OpenRegister and an unresolvable case, because
	 * `CaseAccessGuard` fails closed at every branch.
	 *
	 * MUTATION access, not read access, and the asymmetry with `runs()`
	 * below is deliberate: submitting an inspection result writes an
	 * enforcement finding against a named address onto the case, so the
	 * narrower predicate (`assignee`) is the defensible one. Reading the
	 * results is granted to the wider `assignees` set that works the case.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse The run
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[NoAdminRequired]
	public function submit(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		// Per-object authorization, decided ENTIRELY from stored state: the
		// case is loaded through OpenRegister and its `assignee` compared with
		// the acting uid. Placed before the payload is read at all, so no part
		// of the request can influence it.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$params = $this->request->getParams();
		$checklistId = (string)($params['checklistId'] ?? '');
		if ($checklistId === '') {
			return new JSONResponse(
				['message' => 'checklistId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->runs->submit(
				caseId: $id,
				templateId: $checklistId,
				payload: $params,
				actor: $user->getUID()
			);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// A REJECTED PAYLOAD IS THE CALLER'S FAULT, NOT THE SERVER'S.
			//
			// OpenRegister validates every write against the schema and throws
			// its own ValidationException with a message that already names the
			// offending property -- `Property 'checklist' should match format
			// 'uuid' but 'e2e-checklist' does not`. Without this arm that
			// exception fell through to the `Throwable` catch below and came
			// back as a 500, so a caller sending a bad payload was told the
			// server had broken and every such submission read as an outage.
			// It stayed invisible because the e2e citation covering this
			// endpoint asserted `not.toBe(403)`, which a 500 satisfies.
			//
			// 400 rather than the 422 the RuntimeException arm returns, because
			// this is OpenRegister's verdict on OpenRegister's schema and
			// OpenRegister answers 400 for it on its own endpoints
			// (ValidateObject::handleValidationException); the two APIs should
			// not disagree about the same rejection. 422 stays for the checks
			// this app makes itself, such as a required photo that is missing.
			//
			// NARROW ON PURPOSE. Widening the arm below to return 4xx for every
			// Throwable would make the symptom go away by reporting real server
			// faults as the caller's fault, which is the worse failure: a
			// genuine fault must still be a 500 and must still be logged as an
			// error, and it is, immediately below.
			$this->logger->info(
				'Rejected checklist run for case ' . $id . ': ' . $e->getMessage(),
				['app' => $this->appName]
			);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to submit a checklist run for case ' . $id . ': ' . $e->getMessage(),
				['app' => $this->appName]
			);
			return new JSONResponse(
				['message' => 'Submission failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end submit()

	/**
	 * Every checklist run of a case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
	 *
	 * `GET /api/cases/{id}/checklist-results` is a per-case sub-resource
	 * and `$id` is the CASE uuid, so the case-membership predicate applies
	 * directly. Inspection results carry enforcement findings against a named
	 * address, so this is a per-case read of supervision data and not a
	 * catalogue lookup like the checklist templates (which are admin CRUD and
	 * guarded as such, in ChecklistTemplateController).
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse The runs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[NoAdminRequired]
	public function runs(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$results = $this->runs->runsForCase(caseId: $id, actor: $user->getUID());
		return new JSONResponse(data: $results, statusCode: Http::STATUS_OK);
	}//end runs()

	/**
	 * submit() on its old address.
	 *
	 * Deprecated alias of POST /api/cases/{id}/checklist-results; remove after the next major.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 *
	 * @contract exclude Deprecated path alias for submit(); behaviour covered by the submit() tests.
	 */
	#[NoAdminRequired]
	public function submitLegacyPath(string $id): JSONResponse {
		return $this->submit(id: $id);
	}//end submitLegacyPath()

	/**
	 * runs() on its old address.
	 *
	 * Deprecated alias of GET /api/cases/{id}/checklist-results; remove after the next major.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 *
	 * @contract exclude Deprecated path alias for runs(); behaviour covered by the runs() tests.
	 */
	#[NoAdminRequired]
	public function runsLegacyPath(string $id): JSONResponse {
		return $this->runs(id: $id);
	}//end runsLegacyPath()
}//end class
