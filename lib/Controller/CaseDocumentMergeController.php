<?php

/**
 * Dossiq case document merge controller: a case's documents merged into one PDF by filinq.
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
 * @spec openspec/specs/documents-merge-pdf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\FilinqMergeClientInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Merges ticked documents of a case into one PDF in the case folder.
 *
 * The caller must be allowed to add to the case, because the PDF lands in
 * its folder; filinq then reads every input as the caller.
 *
 * @spec openspec/specs/documents-merge-pdf/spec.md#requirement-a-cases-documents-are-merged-into-one-pdf-by-filinq
 */
class CaseDocumentMergeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request         The request.
	 * @param CaseAccessGuardInterface   $caseAccessGuard The per-case access check.
	 * @param IUserSession      $userSession     The session.
	 * @param FilinqMergeClientInterface $merges          Filinq's merge.
	 * @param IL10N             $l10n            The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly FilinqMergeClientInterface $merges,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Merge the files into one PDF.
	 *
	 * @param string       $caseId  The case UUID.
	 * @param array<mixed> $fileIds The Nextcloud file ids, in order.
	 * @param string       $name    The PDF's name, '' for filinq's own.
	 *
	 * @return JSONResponse Filinq's finished job, or the refusal.
	 *
	 * @spec openspec/specs/documents-merge-pdf/spec.md#requirement-a-cases-documents-are-merged-into-one-pdf-by-filinq
	 */
	#[NoAdminRequired]
	public function merge(string $caseId, array $fileIds=[], string $name=''): JSONResponse {
		$refusal = $this->mergeAccessRefusal(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$ids = array_values(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0));
		if (count($ids) < 2) {
			return new JSONResponse(
				['error' => 'too-few', 'message' => $this->l10n->t('Choose at least two documents to merge')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->runMerge(ids: $ids, name: $name, caseId: $caseId);
	}//end merge()

	/**
	 * Merge the documents into one and answer it, or the failure.
	 *
	 * @param array<int> $ids The file ids, at least two.
	 * @param string $name The merged document's name.
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse
	 */
	private function runMerge(array $ids, string $name, string $caseId): JSONResponse {
		try {
			return new JSONResponse($this->merges->merge(fileIds: $ids, name: $name, caseId: $caseId));
		} catch (Throwable $e) {
			return $this->mergeFailure(e: $e);
		}
	}//end runMerge()

	/**
	 * Refuse a merge to an anonymous caller or a user who may not add documents to the case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null The refusal, or null when the merge may run.
	 */
	private function mergeAccessRefusal(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => 'no-case-access', 'message' => $this->l10n->t('You may not add documents to this case')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end mergeAccessRefusal()

	/**
	 * Answer a failed merge: 503 when Filinq is missing, else 422 with the reason.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function mergeFailure(Throwable $e): JSONResponse {
		if (str_starts_with(haystack: $e->getMessage(), needle: 'filinq_unavailable') === true) {
			return new JSONResponse(
				['error' => 'filinq-unavailable', 'message' => $this->l10n->t('Filinq is not installed, so documents cannot be merged')],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['error' => 'merge-refused', 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end mergeFailure()
}//end class
