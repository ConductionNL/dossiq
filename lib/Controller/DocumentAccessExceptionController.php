<?php

/**
 * Dossiq document access exception controller
 *
 * PUT    /api/informatieobjecten/{infoObjectId}/access-exception  sets or changes it
 * DELETE /api/informatieobjecten/{infoObjectId}/access-exception  removes it
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use RuntimeException;

/**
 * Sets and removes the access exception of one document.
 *
 * Order of every request: the change right on every case of the document (before anything is read), then the read
 * rule on the document itself, then the input, then the write.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
 */
class DocumentAccessExceptionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                        $appName     The app name.
	 * @param IRequest                      $request     The request.
	 * @param IUserSession                  $userSession The caller.
	 * @param DocumentCaseWriteGuard        $caseWrites  The change right on every case of the document.
	 * @param InformatieobjectReader        $reader      The read rule on the document.
	 * @param DocumentAccessExceptionWriter $writer      Writes the exception and restricts the file.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DocumentCaseWriteGuard $caseWrites,
		private readonly InformatieobjectReader $reader,
		private readonly DocumentAccessExceptionWriter $writer,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Set or change the exception: `users`, `groups`, `reason`.
	 *
	 * @param string $infoObjectId The informatieobject id.
	 *
	 * @return JSONResponse The stored exception, or 400, 401, 403, 404, 503.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
	 */
	#[NoAdminRequired]
	public function update(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->ensureMayChange(user: $user, documentId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$record = $this->reader->loadReadable(user: $user, infoObjectId: $infoObjectId);
		if ($record instanceof JSONResponse) {
			return $record;
		}

		try {
			$exception = $this->writer->validated(
				input: [
					'users' => $this->request->getParam('users', []),
					'groups' => $this->request->getParam('groups', []),
					'reason' => $this->request->getParam('reason', ''),
				]
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$stored = $this->writer->set(user: $user, record: $record, exception: $exception);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['error' => 'The access exception was not set: ' . $e->getMessage()],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['accessException' => $stored]);
	}//end update()

	/**
	 * Remove the exception; `reason` is optional.
	 *
	 * @param string $infoObjectId The informatieobject id.
	 *
	 * @return JSONResponse `removed` true or false, or 401, 403, 404, 503.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
	 */
	#[NoAdminRequired]
	public function destroy(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->ensureMayChange(user: $user, documentId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$record = $this->reader->loadReadable(user: $user, infoObjectId: $infoObjectId);
		if ($record instanceof JSONResponse) {
			return $record;
		}

		try {
			$removed = $this->writer->remove(
				user: $user,
				record: $record,
				reason: (string)$this->request->getParam('reason', '')
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['error' => 'The access exception was not removed: ' . $e->getMessage()],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['removed' => $removed]);
	}//end destroy()

	/**
	 * The refusal when the caller may not change a case of the document, else null.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The informatieobject id.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function ensureMayChange(IUser $user, string $documentId): ?JSONResponse {
		$refusedCase = $this->caseWrites->refusedCase(user: $user, documentId: $documentId);
		if ($refusedCase === null) {
			return null;
		}

		return new JSONResponse(
			[
				'error' => 'You may not change a case this document belongs to; nothing was changed',
				'documentId' => $documentId,
				'caseId' => $refusedCase,
			],
			Http::STATUS_FORBIDDEN
		);
	}//end ensureMayChange()
}//end class
