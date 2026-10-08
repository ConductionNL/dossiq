<?php

/**
 * Reading a saved mail file on a case as the message it is.
 *
 * `.eml` and `.msg` land on cases all the time, forwarded by a handler or
 * dropped from a mailbox, and until now a case held them as opaque
 * attachments: a `.msg` cannot be read without Outlook, and a `.eml` reads as
 * headers to anyone who opens it.
 *
 * THE FILE IS RESOLVED AS THE CALLER, not as an admin. `getUserFolder($uid)`
 * plus `getFirstNodeById()` answers null for a node this caller cannot reach,
 * which is the same guard `ScanVerdictController` uses and for the same
 * reason: a 404 rather than a verdict on an unreachable id, so the endpoint
 * is not an existence oracle for the instance's file ids.
 *
 * THE CASE GUARD IS MUTATION, not read. Reading a file as a message WRITES a
 * message onto the case, so it refuses what a change to the case would refuse
 * rather than what reading it would.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Email\SavedMailImport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one saved mail file on a case as a message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class SavedMailController extends Controller {

	/**
	 * How many bytes of one saved message this endpoint will read.
	 *
	 * A saved mail file with a video attached is still a mail file, and
	 * reading twenty megabytes into a string to find a subject line is a way
	 * to take the instance down from a row action. The cap is generous for a
	 * message and firm about the shape of the failure.
	 */
	private const MAX_BYTES = 20971520;

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         Inbound request.
	 * @param SavedMailImport $import          Hands the bytes to integriq's reader.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization.
	 * @param IRootFolder     $rootFolder      Resolves the node as the caller.
	 * @param IUserSession    $userSession     The caller.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SavedMailImport $import,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read one file on a case as a message.
	 *
	 * @param string $caseId The case the file is on.
	 * @param int    $fileId The Nextcloud file id.
	 *
	 * @return JSONResponse What was read, or why it could not be.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	#[NoAdminRequired]
	public function read(string $caseId, int $fileId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($caseId) === '') {
			return new JSONResponse(['error' => 'A case id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: trim($caseId), user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$node = $this->nodeFor(uid: $user->getUID(), fileId: $fileId);
		if ($node === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($node->getSize() > self::MAX_BYTES) {
			return new JSONResponse(
				[
					'outcome' => SavedMailImport::OUTCOME_KEPT,
					'reason' => 'This file is too large to read as a message. It is unchanged on this case.',
				]
			);
		}

		try {
			$raw = (string)$node->getContent();
		} catch (Throwable $e) {
			$this->logger->warning(
				'SavedMailController: a saved mail file could not be read from storage',
				['case' => $caseId, 'file' => $fileId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		// 200 for a KEPT outcome as well as an IMPORTED one, because both are
		// real answers to what the handler asked. A 500 for "integriq is not
		// installed" would be this app reporting its own failure rather than
		// the file's, and the sentence is what the handler needs either way.
		return new JSONResponse(
			$this->import->import(
				caseId: trim($caseId),
				fileName: $node->getName(),
				raw: $raw
			)
		);
	}//end read()

	/**
	 * The file, resolved as the caller, or null when they cannot reach it.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The file id.
	 *
	 * @return File|null The file, or null.
	 */
	private function nodeFor(string $uid, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		if ($node instanceof File) {
			return $node;
		}

		return null;
	}//end nodeFor()
}//end class
