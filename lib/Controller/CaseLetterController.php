<?php

/**
 * Dossiq Case Letter Controller
 *
 * The letter a case type declares for a purpose (`documentDrafts.<purpose>`)
 * and the case's newest document of that type, for the decision dialog's
 * letter row.
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
 * @spec openspec/specs/decision-document/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DecisionDocument\DecisionLetter;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads a case's letter for a purpose.
 *
 * @spec openspec/specs/decision-document/spec.md
 */
class CaseLetterController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string          $appName         The app name.
	 * @param IRequest        $request         The request.
	 * @param DecisionLetter  $letters         Finds the letter on the case.
	 * @param IUserSession    $userSession     The current user.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 * @param CaseDocumentDrafts $drafts       Drafts the declared letter through filinq.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DecisionLetter $letters,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly CaseDocumentDrafts $drafts,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The declared letter for a purpose and the case's newest document of its type.
	 *
	 * @param string $id      The case UUID.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return JSONResponse `{declaration, document}`; 403 without read access to the case.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $id, string $purpose): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->letters->forCase(caseId: $id, purpose: $purpose));
	}//end show()

	/**
	 * Draft the declared letter for a purpose and file it on the case.
	 *
	 * @param string $id      The case UUID.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return JSONResponse The draft outcome and the letter it found afterwards; 422 when nothing was drafted.
	 *
	 * @throws \OCP\AppFramework\OCS\OCSForbiddenException Without mutation access to the case.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	#[NoAdminRequired]
	public function draft(string $id, string $purpose): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->caseAccessGuard->assertCaseMutationAccess(caseId: $id, user: $user);

		$outcome = $this->drafts->draft(caseId: $id, purpose: $purpose);
		if ($outcome['status'] !== 'drafted') {
			return new JSONResponse(['error' => $outcome['status'], 'reason' => $outcome['reason']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(['draft' => $outcome] + $this->letters->forCase(caseId: $id, purpose: $purpose));
	}//end draft()
}//end class
