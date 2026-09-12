<?php

/**
 * Dossiq Inspection Checklist Controller
 *
 * REST endpoints for admin CRUD on inspection checklists and per-case
 * inspection result submission.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\InspectionChecklistService;
use OCA\Dossiq\Settings\AdminSettings;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for inspection checklist CRUD and inspection result submission.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thirteen declared types, and
 * the rule asks for twelve. This was already failing before the 4xx arm below
 * was written, so it is not the exceptions: it is five injected collaborators,
 * the framework's Controller / IRequest / JSONResponse / Http, the OCS
 * forbidden exception, and the two error types the catch arms name. Reducing
 * it means splitting admin checklist CRUD from per-case result submission into
 * two controllers, which is a routing change and a different PR. Same
 * suppression and same reasoning as ZrcController, DrcController,
 * StufController and SubsidieController carry.
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class InspectionChecklistController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param InspectionChecklistService $checklistService Checklist service
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 *
	 * `IGroupManager` is deliberately NOT injected any more (#799). The only
	 * thing it did here was the admin bypass in front of a guard that could
	 * never refuse, and `CaseAccessGuard` performs that same bypass itself, in
	 * one place, next to the per-case check it belongs to.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly InspectionChecklistService $checklistService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all inspection checklists.
	 *
	 * @return JSONResponse List of inspectionChecklist objects
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		$caseTypeRef = $this->request->getParam(key: 'caseTypeRef');
		$checklists = $this->checklistService->listChecklists(caseTypeRef: $caseTypeRef);
		return new JSONResponse(data: $checklists, statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * Create a new inspection checklist.
	 *
	 * @return JSONResponse Created inspectionChecklist object
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		$data = $this->request->getParams();
		// Identity stripped on a CREATE too. `saveObject()` resolves its target
		// from the payload (`@self.id` first, then `id`), so a create carrying
		// either would replace an existing checklist rather than add one.
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->checklistService->createChecklist(data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// Same narrow arm as submitResult() below, for the same reason: a
			// checklist OpenRegister refuses is the author's payload, not a
			// server fault, and its message already names the property.
			$this->logger->info(
				'Rejected inspection checklist create: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to create inspection checklist: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to create checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end create()

	/**
	 * Update an existing inspection checklist.
	 *
	 * @param string $id UUID of the checklist to update
	 *
	 * @return JSONResponse Updated inspectionChecklist object
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->checklistService->updateChecklist(id: $id, data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// Same narrow arm as submitResult() below, for the same reason: a
			// checklist OpenRegister refuses is the author's payload, not a
			// server fault, and its message already names the property.
			$this->logger->info(
				'Rejected inspection checklist update for ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to update inspection checklist ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to update checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end update()

	/**
	 * Delete an inspection checklist.
	 *
	 * @param string $id UUID of the checklist to delete
	 *
	 * @return JSONResponse Success or error
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		$success = $this->checklistService->deleteChecklist(id: $id);
		if ($success === true) {
			return new JSONResponse(data: ['message' => 'Deleted'], statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(
			['message' => 'Failed to delete checklist'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end destroy()

	/**
	 * Submit an inspection result for a case.
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
	 * MUTATION access, not read access, and the asymmetry with `getResults()`
	 * below is deliberate: submitting an inspection result writes an
	 * enforcement finding against a named address onto the case, so the
	 * narrower predicate (`assignee`) is the defensible one. Reading the
	 * results is granted to the wider `assignees` set that works the case.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse Saved inspectionResult object
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[NoAdminRequired]
	public function submitResult(string $id): JSONResponse {
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
			$result = $this->checklistService->submitResult(
				caseId: $id,
				checklistId: $checklistId,
				resultData: $params,
				completedBy: $user->getUID()
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
				'Rejected inspection result for case ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
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
				'Failed to submit inspection result for case ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Submission failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end submitResult()

	/**
	 * Get all inspection results for a case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
	 *
	 * `GET /api/vth/cases/{id}/inspection-results` is a per-case sub-resource
	 * and `$id` is the CASE uuid, so the case-membership predicate applies
	 * directly. Inspection results carry enforcement findings against a named
	 * address, so this is a per-case read of supervision data and not a
	 * catalogue lookup like the checklist DEFINITIONS above (which are admin
	 * CRUD and guarded as such).
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse List of inspectionResult objects
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[NoAdminRequired]
	public function getResults(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$results = $this->checklistService->getResultsForCase(caseId: $id);
		return new JSONResponse(data: $results, statusCode: Http::STATUS_OK);
	}//end getResults()
}//end class
