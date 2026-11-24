<?php
/**
 * Opening a file on a case in the instance's editor.
 *
 * A case's files live in the openregister account's home, outside every
 * person's Files app, so an editor opened by file id finds nothing for the
 * person who asks. This endpoint checks the case and the file the way a
 * change to the case is checked, then shares that one file with the caller
 * (read and update, nothing else) through OpenRegister's own sharing handler
 * and answers with the Files app link that opens it in the editor.
 *
 * Every check runs before the share: the case must allow the caller to change
 * it, the file must sit in the case's own folder, OpenRegister's restriction
 * guard must let the caller read it, and when a document record names the
 * file, every case of that document must allow the write.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentWriteGateInterface;
use OCA\Dossiq\Kernel\Contracts\FileDocumentLookupInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens one case file in the editor for the caller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — every check before the share
 * (case, folder, restriction, document write) is its own contract, and none
 * of them may be skipped.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseFileEditorController extends Controller {

	/**
	 * The account that owns every OpenRegister object folder.
	 */
	public const STORE_USER = 'openregister';

	/**
	 * OpenRegister's sharing handler, named as a string: OpenRegister is an optional dependency.
	 */
	public const SHARING_HANDLER = 'OCA\\OpenRegister\\Files\\Service\\File\\FileSharingHandlerInterface';

	/**
	 * The grant the sharing handler takes.
	 */
	public const SHARE_GRANT = 'OCA\\OpenRegister\\Files\\Service\\File\\UserShareGrant';

	/**
	 * OpenRegister's verdict on a restricted file.
	 */
	public const RESTRICTION_GUARD = 'OCA\\OpenRegister\\Files\\Service\\File\\FileRestrictionGuardInterface';

	/**
	 * Read and update: enough to edit, not to delete, rename or reshare.
	 */
	public const EDIT_PERMISSIONS = 3;

	/**
	 * The office editor app, and the document types it edits in its own page.
	 */
	public const OFFICE_APP = 'onlyoffice';

	public const OFFICE_MIME = '#^application/(vnd\.openxmlformats-officedocument\.|vnd\.oasis\.opendocument\.|msword$|vnd\.ms-(excel|powerpoint)$)#';

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request         Inbound request.
	 * @param CaseAccessGuardInterface    $caseAccessGuard Per-case authorization.
	 * @param DocumentWriteGateInterface  $writeGate       The write rule of a document on several cases.
	 * @param FileDocumentLookupInterface $documents       Which document record names a file.
	 * @param IRootFolder                 $rootFolder      Resolves the file in the store.
	 * @param IUserSession                $userSession     The caller.
	 * @param IURLGenerator               $urlGenerator    Builds the editor link.
	 * @param IAppManager                 $appManager      Whether the office editor is there.
	 * @param ContainerInterface          $container       Resolves OpenRegister's services.
	 * @param LoggerInterface             $logger          Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
		private readonly DocumentWriteGateInterface $writeGate,
		private readonly FileDocumentLookupInterface $documents,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Make one case file editable for the caller and answer with the link that opens it.
	 *
	 * @param string $caseId The case the file is on.
	 * @param int    $fileId The Nextcloud file id.
	 *
	 * @return JSONResponse `{url}`, or why the file cannot be opened.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function open(string $caseId, int $fileId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = trim($caseId);
		if ($caseId === '') {
			return new JSONResponse(['error' => 'A case id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'You may not change this case, so its files cannot be edited.'], Http::STATUS_FORBIDDEN);
		}

		$node = $this->caseFile(caseId: $caseId, fileId: $fileId);
		if ($node === null) {
			return new JSONResponse(['error' => 'This file is not on this case.'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->fileRefusal(user: $user, fileId: $fileId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->share(user: $user, node: $node);
	}//end open()

	/**
	 * The file in the store, or null when it is not in the case's own folder.
	 *
	 * The store keeps an object's files in a folder named after the object's
	 * uuid under its register's folder, so a file is on the case when that
	 * folder is one of its ancestors.
	 *
	 * @param string $caseId The case.
	 * @param int    $fileId The file id.
	 *
	 * @return File|null
	 */
	private function caseFile(string $caseId, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder(self::STORE_USER)->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		if ($node instanceof File === false || str_contains($node->getPath(), '/' . $caseId . '/') === false) {
			return null;
		}

		return $node;
	}//end caseFile()

	/**
	 * Refuse a file the caller may not read (a restriction) or write (another case of its document).
	 *
	 * @param IUser $user   The caller.
	 * @param int   $fileId The file id.
	 *
	 * @return JSONResponse|null The refusal, or null when the file may be edited.
	 */
	private function fileRefusal(IUser $user, int $fileId): ?JSONResponse {
		try {
			$guard = $this->container->get(self::RESTRICTION_GUARD);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'OpenRegister is not available.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($guard->mayRead($fileId) !== true) {
			return new JSONResponse(['error' => 'This file is restricted, and you are not one of its readers.'], Http::STATUS_FORBIDDEN);
		}

		$documentId = $this->documents->recordIdOfFile(fileId: $fileId);
		if ($documentId === '') {
			return null;
		}

		return $this->writeGate->refusedWrite(user: $user, documentId: $documentId);
	}//end fileRefusal()

	/**
	 * Share the file with the caller for editing and answer with the link that opens it.
	 *
	 * @param IUser $user The caller.
	 * @param File  $node The file.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress UndefinedClass OpenRegister is an optional dependency; a missing class is caught below.
	 */
	private function share(IUser $user, File $node): JSONResponse {
		try {
			$grantClass = self::SHARE_GRANT;
			$this->container->get(self::SHARING_HANDLER)->shareFileWithUser(
				new $grantClass(node: $node, userId: $user->getUID(), permissions: self::EDIT_PERMISSIONS)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseFileEditorController: a case file could not be shared for editing',
				['file' => $node->getId(), 'user' => $user->getUID(), 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'This file could not be opened for editing.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['fileId' => $node->getId(), 'url' => $this->editorUrl(user: $user, node: $node)]);
	}//end share()

	/**
	 * The link that opens the file: the office editor's own page for an office document, else the Files app.
	 *
	 * @param IUser $user The caller.
	 * @param File  $node The file.
	 *
	 * @return string
	 */
	private function editorUrl(IUser $user, File $node): string {
		if ($this->appManager->isEnabledForUser(self::OFFICE_APP, $user) === true
			&& preg_match(self::OFFICE_MIME, $node->getMimetype()) === 1
		) {
			return $this->urlGenerator->linkToRoute(self::OFFICE_APP . '.editor.index', ['fileId' => $node->getId()]);
		}

		return $this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => $node->getId()]);
	}//end editorUrl()
}//end class
