<?php

/**
 * Dossiq case access purpose controller
 *
 * `POST /api/cases/{id}/access-purpose`: the purpose a reader states before a
 * case whose case type processes special categories of personal data opens
 * (nextcloud-vue detail page `accessPurpose`).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessPurposeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Records the purpose of opening a sensitive case.
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */
class CaseAccessPurposeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     Inbound request.
	 * @param CaseAccessPurposeService $purposes    Records the purpose.
	 * @param IUserSession             $userSession The reader.
	 * @param IL10N                    $l10n        The translations.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessPurposeService $purposes,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Record the reader's purpose for opening this case. The case is read as
	 * the reader, so a case they may not open is refused.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse 201 recorded, 200 when the case asks no purpose, else the refusal.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
	 */
	#[NoAdminRequired]
	public function record(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->purposes->record(
				caseId: $id,
				readerId: $user->getUID(),
				purpose: (string)$this->request->getParam('purpose', ''),
				note: (string)$this->request->getParam('note', ''),
			);
		} catch (RefusedException $e) {
			return new JSONResponse(
				['error' => $e->getRule(), 'message' => $this->l10n->t($e->getSentence())],
				$e->getStatus()
			);
		}

		$status = Http::STATUS_OK;
		if ($result['recorded'] === true) {
			$status = Http::STATUS_CREATED;
		}

		return new JSONResponse($result, $status);
	}//end record()

	/**
	 * Whether the reader must state a purpose to open this case. The case's
	 * own handler need not: the purpose is recorded for them and the answer
	 * is `required: false`.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{ required, recorded }`, or the refusal.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	#[NoAdminRequired]
	public function check(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->purposes->check(caseId: $id, readerId: $user->getUID());
		} catch (RefusedException $e) {
			return new JSONResponse(
				['error' => $e->getRule(), 'message' => $this->l10n->t($e->getSentence())],
				$e->getStatus()
			);
		}

		return new JSONResponse($result);
	}//end check()
}//end class
