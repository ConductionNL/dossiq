<?php

/**
 * Dossiq Bezwaar Hearing Controller
 *
 * REST API for the bezwaar hoorzitting (Awb art. 7:2) operations that the
 * manifest-driven CRUD path cannot express. The `hearingSession` schema has
 * no manifest page, and `attendance` is append-only with a one-hour grace
 * window after the session concludes: past that window every correction must
 * carry a documented `correctionReason` and is written as an awb-art-7:7
 * audit entry. A generic schema edit form would replace the array wholesale
 * and write no audit entry, so this operation needs a domain endpoint.
 *
 * Scoped deliberately to the one domain operation on
 * {@see \OCA\Dossiq\Service\Bezwaar\HearingService} that has no other way
 * in — scheduling, waiver and minutes are reached through the bezwaar
 * lifecycle listener and are not re-exposed here.
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Bezwaar\HearingService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Controller for bezwaar hearing (hoorzitting) attendance capture.
 *
 * The endpoint carries the NoAdminRequired annotation and requires an
 * authenticated session.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class BezwaarHearingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param HearingService $hearingService The bezwaar hearing domain service
	 * @param IUserSession $userSession The user session
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HearingService $hearingService,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Append attendance entries to a hearingSession (REQ-BH-5).
	 *
	 * Within the one-hour grace window after the hearing concludes entries
	 * are appended freely; past the window the service requires a non-empty
	 * `correctionReason` per entry and writes an awb-art-7:7 audit entry.
	 * Neither rule is enforceable through a generic schema edit form, which
	 * is why this is a domain endpoint rather than manifest CRUD.
	 *
	 * The auth posture used to be `@NoAdminRequired` plus an explicit 401 and
	 * nothing else, on the reasoning that attendance is recorded by the
	 * behandelaar or the committee secretary, neither of whom is an
	 * administrator, so an admin-only posture would lock out exactly the roles
	 * that perform this task. That half was right — but "not admin-only" was
	 * implemented as "any authenticated account", which is a 401 where an
	 * authorisation check belongs. The posture is now per-case: the parent
	 * bezwaar case is resolved from the session and
	 * `CaseAccessGuard::hasCaseMutationAccess()` decides, which admits exactly
	 * the behandelaar the old comment was protecting.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 *
	 * @return JSONResponse The updated hearingSession, 400 on a rejected
	 *                      correction, 401 when unauthenticated, 403 when the
	 *                      caller does not handle the parent case
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function recordAttendance(string $sessionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Writes an awb-art-7:7 audit entry against a hearing session picked
		// by uuid; the parent bezwaar case is resolved first so the ordinary
		// per-case guard applies. An unresolvable session denies.
		$caseId = $this->hearingService->getCaseIdForSession(sessionId: $sessionId);
		if ($caseId === null
			|| $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$entries = $this->request->getParam('entries');
		if (is_array($entries) === false) {
			$entries = [];
		}

		try {
			return new JSONResponse(
				$this->hearingService->recordAttendance(
					sessionId: $sessionId,
					entries: array_values($entries)
				)
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end recordAttendance()
}//end class
