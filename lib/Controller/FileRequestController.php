<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\People\FileRequestService;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Asks a party of a case for a file, and says who can be asked.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
class FileRequestController extends Controller {

	/**
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param PersonLinkReader $people The people on the case.
	 * @param FileRequestService $fileRequests Sends the request.
	 * @param CaseAccessGuard $access Whether this handler may see this case at all.
	 * @param IUserSession $userSession The signed-in handler.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PersonLinkReader $people,
		private readonly FileRequestService $fileRequests,
		private readonly CaseAccessGuard $access,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The parties of a case, each with whether a file can be requested from them.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The parties.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function parties(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Who is on a case is as sensitive as the case: scope it to this
		// handler's own access rather than to being signed in at all.
		if ($this->access->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
		}

		$parties = [];
		foreach ($this->people->peopleOn(caseId: $caseId) as $person) {
			$email = $this->people->emailOf(link: $person);
			$parties[] = [
				'id' => (string)($person['contactUid'] ?? ''),
				'name' => $this->people->nameOf(link: $person),
				'email' => $email,
				'role' => (string)($person['role'] ?? ''),
				'kind' => (string)($person['kind'] ?? 'contact'),
				'canBeAsked' => ($email !== ''),
			];
		}

		return new JSONResponse(['parties' => $parties]);
	}//end parties()

	/**
	 * Ask one party for a file.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $personId The party's uid.
	 * @param string $note What they are asked for.
	 * @param int $days How long the request stands.
	 *
	 * @return JSONResponse The recipient and the request.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function create(string $caseId, string $personId = '', string $note = '', int $days = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Asking a party for a file shares this case's folder with them, so it
		// takes the same access as changing the case, and a handler who cannot
		// see the case is told it does not exist rather than that it does.
		if ($this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
		}

		if (trim($personId) === '') {
			return new JSONResponse(['error' => 'Name the party to ask'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$sent = $this->fileRequests->request(
				caseId: $caseId,
				personUid: trim($personId),
				note: trim($note),
				days: $days,
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $this->statusOf(code: (int)$e->getCode()));
		}

		return new JSONResponse($sent, Http::STATUS_CREATED);
	}//end create()

	/**
	 * A service code as an HTTP status.
	 *
	 * @param int $code The exception's code.
	 *
	 * @return int The status.
	 */
	private function statusOf(int $code): int {
		if (in_array($code, [400, 401, 403, 404, 422], true) === true) {
			return $code;
		}

		return Http::STATUS_INTERNAL_SERVER_ERROR;
	}//end statusOf()
}//end class
