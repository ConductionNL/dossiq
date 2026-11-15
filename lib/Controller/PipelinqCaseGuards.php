<?php

/**
 * Dossiq Pipelinq Case Guards.
 *
 * The caller checks both pipelinq case controllers make before they touch a
 * case: signed in, then allowed to read or to change THIS case (CaseAccessGuard,
 * fail closed). One place, so the two controllers cannot drift apart on what a
 * refusal looks like.
 *
 * The using class provides `$access` (CaseAccessGuard), `$userSession`
 * (IUserSession) and `$l10n` (IL10N).
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
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Caller checks shared by the pipelinq case controllers.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
trait PipelinqCaseGuards {

	/**
	 * Refuse a caller who may not read this case; null when they may.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function refuseUnlessReader(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return null;
	}//end refuseUnlessReader()

	/**
	 * Refuse a caller who may not change this case; null when they may.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function refuseUnlessEditor(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return null;
	}//end refuseUnlessEditor()

	/**
	 * The answer for a caller with no session.
	 *
	 * @return JSONResponse
	 */
	private function notSignedIn(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('You are not signed in.')], Http::STATUS_UNAUTHORIZED);
	}//end notSignedIn()

	/**
	 * The answer for a caller without access to the case.
	 *
	 * @return JSONResponse
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('You do not have access to this case.')], Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end trait
