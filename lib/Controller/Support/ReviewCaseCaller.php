<?php

/**
 * Dossiq review case caller.
 *
 * The signed-in caller of a review route, when they may read or change the
 * case: a 401 `not-authenticated` without a session user, the translated 403
 * without access. Split out of
 * {@see \OCA\Dossiq\Controller\DocumentReviewController}, which keeps the
 * review writes, the summary, the batches and their refusal sentences.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Who may read or change a case under review, asked of the case access guard.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ReviewCaseCaller {
	use AnswersNoCaseAccess;

	/**
	 * Constructor.
	 *
	 * @param IUserSession             $userSession The session.
	 * @param CaseAccessGuardInterface $accessGuard The per-case access check.
	 * @param IL10N                    $l10n        Translates the 403.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $accessGuard,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The signed-in user when they may read the case, else the refusal.
	 *
	 * @param string $id The case.
	 *
	 * @return IUser|JSONResponse The user, or 401 without a session user, or 403 without read access.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requireReader(string $id): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		return $user;
	}//end requireReader()

	/**
	 * The signed-in user when they may change the case, else the refusal.
	 *
	 * @param string $id The case.
	 *
	 * @return IUser|JSONResponse The user, or 401 without a session user, or 403 without mutation access.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requireWriter(string $id): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		return $user;
	}//end requireWriter()
}//end class
