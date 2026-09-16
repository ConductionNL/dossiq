<?php

/**
 * Dossiq case conversation controller.
 *
 * The REST surface in front of {@see CaseConversationService} and
 * {@see CaseCaptureService}: start a live conversation from a case, record
 * what it was, attach a capture, declare a case major and close its channel.
 *
 * Every endpoint is `#[NoAdminRequired]` and authenticated, and every one of
 * them asks {@see CaseAccessGuard} for the case first, so a user can only act
 * on a case they may already act on. Reads use the read guard and writes the
 * mutation guard.
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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Conversation\CaseCaptureService;
use OCA\Dossiq\Service\Conversation\CaseConversationService;
use OCA\Dossiq\Service\Conversation\MajorCaseDeclaration;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST controller for live conversations, captures and major declarations.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseConversationController extends Controller {

	use TranslatesRefusals;

	/**
	 * Map service refusal reasons onto HTTP status codes.
	 *
	 * @var array<string, int>
	 */
	private const REASON_STATUS = [
		CaseConversationService::REASON_NO_TALK => Http::STATUS_SERVICE_UNAVAILABLE,
		CaseConversationService::REASON_NO_CASE => Http::STATUS_NOT_FOUND,
		CaseConversationService::REASON_UNKNOWN_ROOM => Http::STATUS_NOT_FOUND,
		CaseConversationService::REASON_UNRESOLVED_RESPONDERS => Http::STATUS_CONFLICT,
		CaseCaptureService::REASON_NOT_A_CAPTURE => Http::STATUS_BAD_REQUEST,
		CaseCaptureService::REASON_EMPTY => Http::STATUS_BAD_REQUEST,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request         Inbound request.
	 * @param CaseConversationService  $conversations   Conversations and the record they leave.
	 * @param MajorCaseDeclaration     $major           Declaring a case major, and closing its channel.
	 * @param CaseCaptureService       $captures        Voice notes and screen captures.
	 * @param CaseAccessGuard          $caseAccessGuard Per-case authorization, failing closed.
	 * @param IUserSession             $userSession     Current user session.
	 * @param LoggerInterface          $logger          Logger, used by the refusal translator.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseConversationService $conversations,
		private readonly MajorCaseDeclaration $major,
		private readonly CaseCaptureService $captures,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether this instance can hold a conversation, and why not when it cannot.
	 *
	 * Per-object guard: none needed, this answers about the instance and
	 * discloses nothing about any case.
	 *
	 * @return JSONResponse The affordance and its reason.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->conversations->availability());
	}//end availability()

	/**
	 * Start a live conversation from a case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * @param string      $caseId  Case UUID.
	 * @param string|null $subject What the conversation is about.
	 *
	 * @return JSONResponse The conversation record, or a refusal.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function start(string $caseId, ?string $subject = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['ok' => false, 'reason' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			return $this->answer(
				result: $this->conversations->startConversation(
					caseId: $caseId,
					subject: $subject,
					startedBy: $user->getUID(),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'start a conversation', e: $e);
		}
	}//end start()

	/**
	 * Record that a conversation ended: who joined, and for how long.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * @param string        $caseId          Case UUID, from the URL.
	 * @param string        $roomId          The Talk conversation id, from the body.
	 * @param array<string> $participants    Who joined.
	 * @param int           $durationSeconds How long it lasted.
	 *
	 * @return JSONResponse The completed record, or a refusal.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function end(string $caseId, string $roomId = '', array $participants = [], int $durationSeconds = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['ok' => false, 'reason' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(
			result: $this->conversations->recordConversationEnd(
				caseId: $caseId,
				roomId: $roomId,
				participants: $participants,
				durationSeconds: $durationSeconds,
			)
		);
	}//end end()

	/**
	 * Attach a voice note or a screen capture to a case, or to a task on it.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * @param string      $caseId   Case UUID.
	 * @param string      $fileName The file the recorder produced.
	 * @param string      $mimeType Its media type.
	 * @param string      $content  Its bytes, base64 encoded.
	 * @param string|null $taskId   The task it belongs to, when there is one.
	 *
	 * @return JSONResponse The case document, or a refusal.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function capture(
		string $caseId,
		string $fileName = '',
		string $mimeType = '',
		string $content = '',
		?string $taskId = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['ok' => false, 'reason' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(
			result: $this->captures->attachCapture(
				caseId: $caseId,
				fileName: $fileName,
				mimeType: $mimeType,
				content: $content,
				taskId: $taskId,
				author: $user->getUID(),
			)
		);
	}//end capture()

	/**
	 * Declare a case major, opening exactly one working channel.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return JSONResponse The channel, or a refusal naming the group that did not resolve.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function declareMajor(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['ok' => false, 'reason' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			return $this->answer(
				result: $this->major->declareMajor(caseId: $caseId, declaredBy: $user->getUID())
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'declare a case major', e: $e);
		}
	}//end declareMajor()

	/**
	 * Close a major case's working channel, keeping what was said on the case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return JSONResponse Whether a channel was closed, or a refusal.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function closeMajorChannel(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['ok' => false, 'reason' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(result: $this->major->closeMajorChannel(caseId: $caseId));
	}//end closeMajorChannel()

	/**
	 * Turn a service result into a response, with the refusal's own status.
	 *
	 * @param array<string, mixed> $result What the service returned.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function answer(array $result): JSONResponse {
		if (($result['ok'] ?? false) === true) {
			return new JSONResponse($result);
		}

		$reason = (string)($result['reason'] ?? '');

		return new JSONResponse(
			$result,
			(self::REASON_STATUS[$reason] ?? Http::STATUS_BAD_REQUEST)
		);
	}//end answer()

}//end class
