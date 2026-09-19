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

use OCA\Dossiq\Service\Documents\ScanVerdictReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What the virus scanner recorded about a file, for the row that shows it.
 *
 * 🔴 THE FILE ID IS THE WHOLE REQUEST, so the file id cannot be the whole
 * authorisation. A scan verdict is a fact about someone's document, and an
 * endpoint that answered any authenticated caller for any id would leak which
 * files exist and which of them are infected, one id at a time. The node is
 * therefore resolved in the CALLER'S OWN folder: a user who cannot see the
 * file gets the same 404 as a file that is not there, and learns nothing from
 * the difference.
 *
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
class ScanVerdictController extends Controller {

	/**
	 * @param string            $appName     The app id.
	 * @param IRequest          $request     The request.
	 * @param ScanVerdictReader $verdicts    Reads what the scanner recorded.
	 * @param IRootFolder       $rootFolder  Resolves the node as the caller.
	 * @param IUserSession      $userSession Who asks.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ScanVerdictReader $verdicts,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The verdict for one file the caller can reach.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return JSONResponse The state, the moment of the check, and whether a
	 *                      scanner is installed at all.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	#[NoAdminRequired]
	public function show(int $fileId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayAccessFile(uid: $user->getUID(), fileId: $fileId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->verdicts->verdictFor(fileId: $fileId));
	}//end show()

	/**
	 * Whether this caller can see this file at all.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The Nextcloud file id.
	 *
	 * @return bool True when the file is in the caller's own folder.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	private function mayAccessFile(string $uid, int $fileId): bool {
		if ($fileId <= 0) {
			return false;
		}

		try {
			$folder = $this->rootFolder->getUserFolder($uid);
			return $folder->getFirstNodeById($fileId) !== null;
		} catch (\Throwable) {
			return false;
		}
	}//end mayAccessFile()
}//end class
