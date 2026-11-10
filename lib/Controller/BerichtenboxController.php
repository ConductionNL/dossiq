<?php

/**
 * Dossiq Berichtenbox Controller.
 *
 * REST endpoints for sending, listing and polling Mijn Overheid Berichtenbox
 * messages linked to cases.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing Berichtenbox send/list/poll endpoints.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class BerichtenboxController extends Controller {

	/**
	 * Refusal codes that mean no digital post provider is set up on this
	 * instance; the send answers them as `provider-not-configured`.
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_MISSING_CODES = [
		'integriq-missing',
		'integriq-seam-missing',
		'digital-post-source-unset',
		'provider-not-configured',
	];

	/**
	 * The categories a handler may send by digital post (opt-out-before-send).
	 */
	private const CATEGORIES = ['case-update', 'besluit', 'statutory'];
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param BerichtenboxService $berichtenboxService The Berichtenbox service.
	 * @param IUserSession $userSession The user session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		IRequest $request,
		private BerichtenboxService $berichtenboxService,
		private IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send a Berichtenbox message for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function send(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId');
		$category = (string)$this->request->getParam('category', 'case-update');
		$refusal = ($this->sendRefusal(caseId: $caseId, user: $user) ?? $this->categoryRefusal(category: $category));
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->berichtenboxService->sendMessage(
			$caseId,
			$this->request->getParam('bsn', ''),
			$this->request->getParam('subject', ''),
			$this->request->getParam('body', ''),
			$this->request->getParam('berichtTypeCode', ''),
			$this->request->getParam('attachmentFileId'),
			$category
		);

		if (isset($result['error']) === true) {
			return $this->notSent(result: $result);
		}

		return new JSONResponse(['success' => true, 'message' => $result]);
	}//end send()

	/**
	 * Refuse a send without a case, or by a caller who may not change it.
	 *
	 * @param mixed      $caseId The case the message is about.
	 * @param \OCP\IUser $user   The caller.
	 *
	 * @return JSONResponse|null The 400 or 403, or null when the send may go ahead.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function sendRefusal(mixed $caseId, \OCP\IUser $user): ?JSONResponse {
		if (empty($caseId) === true) {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
		}

		// Dispatches an official government message, with attachment, into a
		// citizen's statutory message box. Externally visible and not
		// undoable, so this is the strictest guard in the file.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: (string)$caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end sendRefusal()

	/**
	 * Refuse a message category this app does not know.
	 *
	 * The category decides, not the channel: a besluit by Berichtenbox is
	 * always delivered, a case update respects the citizen's opt-out.
	 *
	 * @param string $category The category.
	 *
	 * @return JSONResponse|null The 400, or null for a known category.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function categoryRefusal(string $category): ?JSONResponse {
		if (in_array($category, self::CATEGORIES, true) === false) {
			return new JSONResponse(
				['success' => false, 'code' => 'invalid-category', 'error' => 'Unknown message category.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end categoryRefusal()

	/**
	 * The 400 a message the service did not send answers with.
	 *
	 * The message is still recorded as not sent, with this reason
	 * (BerichtenboxService::storedSend()). A missing provider is named as such.
	 *
	 * @param array<string, mixed> $result The service's answer, carrying `error` and maybe `code`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function notSent(array $result): JSONResponse {
		$answer = ['success' => false, 'error' => $result['error']];
		if (isset($result['code']) === true) {
			$answer['code'] = $result['code'];
		}

		if (in_array(($result['code'] ?? ''), self::PROVIDER_MISSING_CODES, true) === true) {
			$answer['code']  = 'provider-not-configured';
			$answer['cause'] = $result['code'];
		}

		return new JSONResponse($answer, 400);
	}//end notSent()

	/**
	 * List Berichtenbox messages linked to a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function messages(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		if ($caseId === '') {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
		}

		// Official correspondence with a citizen about this case.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$messages = $this->berichtenboxService->getMessagesForCase($caseId);
		return new JSONResponse(['success' => true, 'messages' => $messages]);
	}//end messages()
}//end class
