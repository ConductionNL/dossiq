<?php

/**
 * Dossiq document redaction controller.
 *
 * The redaction step of a case whose documents are assessed per document:
 * list the partly public documents, detect what to redact, record the
 * officer's check, have filinq write the redacted copy, or record an uploaded
 * redacted version. Every route is the case handler's: the same per-case
 * mutation rule as the assessment itself, checked before anything is read.
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
 * @spec openspec/specs/document-redaction/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Redaction\DocumentRedactionService;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * REST API of the redaction step.
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
class DocumentRedactionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param DocumentRedactionService $redactions  The redaction step.
	 * @param CaseAccessGuard          $caseAccess  Per-case mutation authorization (fails closed).
	 * @param DossierUploadHandler     $uploads     Reads an uploaded file and refuses executables.
	 * @param IUserSession             $userSession The signed-in officer.
	 * @param IL10N                    $l10n        The refusals this controller writes itself.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DocumentRedactionService $redactions,
		private readonly CaseAccessGuard $caseAccess,
		private readonly DossierUploadHandler $uploads,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The partly public documents of a case and where each one's redaction stands.
	 *
	 * @param string $id The case.
	 *
	 * @return JSONResponse `{documents: [...]}`
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		return $this->asHandler(caseId: $id, action: fn (): array => ['documents' => $this->redactions->forCase(caseId: $id)]);
	}//end index()

	/**
	 * Detect the passages of one document.
	 *
	 * @param string $id          The case.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse `{documentRef, title, text, redaction, redactedDocumentRef}`
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	#[NoAdminRequired]
	public function detect(string $id, string $documentRef): JSONResponse {
		return $this->asHandler(
			caseId: $id,
			action: fn (IUser $user): array => $this->redactions->detect(caseId: $id, documentRef: $documentRef, userId: $user->getUID())
		);
	}//end detect()

	/**
	 * Record the officer's check of a list of passages.
	 *
	 * @param string $id          The case.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse `{redaction}`
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	#[NoAdminRequired]
	public function check(string $id, string $documentRef): JSONResponse {
		$entities = $this->request->getParam('entities', []);
		if (is_array($entities) === false) {
			$entities = [];
		}

		return $this->asHandler(
			caseId: $id,
			action: fn (IUser $user): array => [
				'redaction' => $this->redactions->check(caseId: $id, documentRef: $documentRef, entities: $entities, userId: $user->getUID()),
			]
		);
	}//end check()

	/**
	 * Have filinq write the redacted copy of the checked passages.
	 *
	 * @param string $id          The case.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse `{redactedDocumentRef, redaction}`
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	#[NoAdminRequired]
	public function redact(string $id, string $documentRef): JSONResponse {
		return $this->asHandler(
			caseId: $id,
			action: fn (IUser $user): array => $this->redactions->redact(caseId: $id, documentRef: $documentRef, userId: $user->getUID())
		);
	}//end redact()

	/**
	 * Record an uploaded redacted version.
	 *
	 * @param string $id          The case.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse `{redactedDocumentRef, redaction}`
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-an-officer-can-upload-a-redacted-version-instead-req-drd-004
	 */
	#[NoAdminRequired]
	public function upload(string $id, string $documentRef): JSONResponse {
		return $this->asHandler(
			caseId: $id,
			action: function (IUser $user) use ($id, $documentRef): array {
				$files = $this->uploads->normaliseUploadedFiles(uploaded: $this->request->getUploadedFile('file'));
				$file = ($files[0] ?? []);
				$name = (string)($file['name'] ?? '');
				$tmpName = (string)($file['tmp_name'] ?? '');
				if ($tmpName !== '' && $this->uploads->isExecutable(name: $name, tmpName: $tmpName) === true) {
					throw new RefusedException(
						rule: 'executable-upload',
						sentence: $this->l10n->t('An executable file cannot be a redacted version.'),
						status: RefusedException::STATUS_UNPROCESSABLE
					);
				}

				$content = '';
				if ($tmpName !== '' && is_readable($tmpName) === true) {
					$content = (string)file_get_contents($tmpName);
				}

				return $this->redactions->upload(caseId: $id, documentRef: $documentRef, fileName: $name, content: $content, userId: $user->getUID());
			}
		);
	}//end upload()

	/**
	 * Run an action for the case's handler, answering a refusal with its own status and sentence.
	 *
	 * @param string   $caseId The case.
	 * @param callable $action `fn (IUser $user): array`, run only after the access check.
	 *
	 * @return JSONResponse
	 */
	private function asHandler(string $caseId, callable $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => 'not-the-handler', 'message' => $this->l10n->t('Only the handler of this case can redact its documents.')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			return new JSONResponse($action($user));
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getRule(), 'message' => $e->getSentence()], $e->getStatus());
		}
	}//end asHandler()
}//end class
