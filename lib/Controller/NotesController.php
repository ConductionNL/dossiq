<?php

/**
 * Dossiq Notes Controller.
 *
 * Thin endpoint for note-typed side-effects that the shared nc-vue notes
 * surface (CnNotesTab, nc-vue #207) cannot own itself: turning a saved
 * note's `@mention` tokens into real Nextcloud notifications. Note
 * storage/CRUD stays entirely inside the OpenRegister integration leaf
 * (ADR-022) — this controller does not read or write notes, it only
 * reacts to the `mention` event the frontend forwards after a note with
 * mentions has already been saved.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\NotePushInterface;
use OCA\Dossiq\Service\MentionNotificationService;
use OCA\Dossiq\Service\Notes\MentionedNote;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for note-mention notification side-effects.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class NotesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request
	 * @param MentionNotificationService $mentionSvc The mention notification service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 * @param NotePushInterface $notePush Hands a note to integriq for the neighbouring register, or says why it did not
	 * @param CaseAccessGuardInterface $caseAccessGuard Decides whether the caller may write notes on this case
	 * @param IUserManager $userManager Tells which mentioned user ids have an account
	 */
	public function __construct(
		IRequest $request,
		private readonly MentionNotificationService $mentionSvc,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly NotePushInterface $notePush,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
		private readonly IUserManager $userManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send one case note to the neighbouring register, or say why it stayed.
	 *
	 * A DELIBERATE ACT RATHER THAN A HOOK ON SAVE, and that is measured rather
	 * than preferred. Note storage is OpenRegister's entirely (ADR-022): this
	 * app never reads or writes a note, and OpenRegister dispatches no
	 * note-saved event dossiq could listen for. `notes#mention` beside this
	 * method exists for the same reason and works the same way, as a call the
	 * frontend makes after the note is already stored. Pushing on save needs
	 * an event that does not exist yet, and that is an openregister row.
	 *
	 * The GUARD IS MUTATION, not read. Sending a note to another organisation
	 * is externally visible and not undoable, so it refuses what a change to
	 * the case would refuse rather than what reading it would.
	 *
	 * @param string $caseId The case the note is on.
	 *
	 * @return JSONResponse The outcome, or a refusal.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	#[NoAdminRequired]
	public function push(string $caseId): JSONResponse {
		$refusal = $this->pushRefusal(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$data = $this->readJsonBody();
		$note = $data['note'] ?? null;
		if (is_array($note) === false || $note === []) {
			return new JSONResponse(['error' => 'A note is required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->pushNote(caseId: $caseId, note: $note);
	}//end push()

	/**
	 * Refuse a push without a session (401), without a case id (400) or by a
	 * caller who may not change the case (403).
	 *
	 * @param string $caseId The case the note is on.
	 *
	 * @return JSONResponse|null The refusal, or null when the push may go ahead.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function pushRefusal(string $caseId): ?JSONResponse {
		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($caseId) === '') {
			return new JSONResponse(['error' => 'A case id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $actor) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end pushRefusal()

	/**
	 * Push the note and answer with its outcome; a push that throws is a logged 500.
	 *
	 * @param string               $caseId The case the note is on.
	 * @param array<string, mixed> $note   The note.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function pushNote(string $caseId, array $note): JSONResponse {
		try {
			$outcome = $this->notePush->push(caseId: $caseId, note: $note);
		} catch (\Throwable $e) {
			$this->logger->error(
				'NotesController: a note push failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'The note could not be sent'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		// 200 for every outcome INCLUDING a failure, because the caller asked
		// what happened and every answer here is a real answer. The outcome is
		// in the body, where the notes panel reads it; a 500 for a refused
		// push would be this app reporting its own success or failure rather
		// than the note's.
		return new JSONResponse($outcome);
	}//end pushNote()

	/**
	 * Notify every user mentioned in a just-saved note.
	 *
	 * Body shape (matches `CnNotesTab`'s `mention` event payload verbatim,
	 * see nc-vue's CnNotesTab.vue): `{ objectId, register, schema, noteId,
	 * mentionedUserIds }`. Best-effort: a failure here must never surface
	 * as an error to the note author, since the note itself is already
	 * saved by the time this endpoint is called — hence the try/catch
	 * around the delegate call still returns 200 with a soft error flag
	 * rather than a 5xx.
	 *
	 * The caller must be able to read the case the note is on, and only
	 * mentioned users with an account who may read the case are notified.
	 *
	 * @return JSONResponse Dispatch result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	#[NoAdminRequired]
	public function mention(): JSONResponse {
		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->readJsonBody();

		$objectId = (string)($data['objectId'] ?? '');
		if ($objectId === '' || $this->mentionedIds(data: $data) === []) {
			return new JSONResponse(
				['error' => 'objectId and a non-empty mentionedUserIds array are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $objectId, user: $actor) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return $this->notifyMentioned(actor: $actor, data: $data);
	}//end mention()

	/**
	 * The user ids the body mentions, as strings, without empties.
	 *
	 * @param array<string, mixed> $data The request body.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function mentionedIds(array $data): array {
		$mentionedUserIdsRaw = $data['mentionedUserIds'] ?? [];
		if (is_array($mentionedUserIdsRaw) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $mentionedUserIdsRaw)));
	}//end mentionedIds()

	/**
	 * Notify the mentioned users who exist; a failed dispatch is a logged 500.
	 *
	 * @param IUser                $actor The caller who mentioned them.
	 * @param array<string, mixed> $data  The request body.
	 *
	 * @return JSONResponse How many were notified.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function notifyMentioned(IUser $actor, array $data): JSONResponse {
		$mentionedUserIds = $this->caseReaders(caseId: (string)$data['objectId'], userIds: $this->mentionedIds(data: $data));
		if ($mentionedUserIds === []) {
			return new JSONResponse(['notified' => 0], Http::STATUS_OK);
		}

		try {
			$notified = $this->mentionSvc->notifyMention(
				actorUserId: $actor->getUID(),
				actorDisplayName: $actor->getDisplayName(),
				note: $this->mentionedNote(data: $data),
				mentionedUserIds: $mentionedUserIds,
			);
		} catch (\Throwable $e) {
			return $this->mentionFailed(exception: $e);
		}

		return new JSONResponse(['notified' => $notified], Http::STATUS_OK);
	}//end notifyMentioned()

	/**
	 * The note the body names.
	 *
	 * @param array<string, mixed> $data The request body.
	 *
	 * @return MentionedNote
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function mentionedNote(array $data): MentionedNote {
		return new MentionedNote(
			objectId: (string)($data['objectId'] ?? ''),
			register: (string)($data['register'] ?? ''),
			schema: (string)($data['schema'] ?? ''),
			noteId: (string)($data['noteId'] ?? '')
		);
	}//end mentionedNote()

	/**
	 * The logged 500 a failed mention dispatch answers with.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function mentionFailed(\Throwable $exception): JSONResponse {
		$this->logger->error(
			'Failed to dispatch note mention notifications: ' . $exception->getMessage(),
			['app' => 'dossiq']
		);
		return new JSONResponse(
			['error' => 'Could not dispatch mention notifications: ' . $exception->getMessage()],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end mentionFailed()

	/**
	 * The user ids of existing accounts that may read the case, in their given order.
	 *
	 * @param string             $caseId  The case the note is on.
	 * @param array<int, string> $userIds The mentioned user ids.
	 *
	 * @return array<int, string>
	 */
	private function caseReaders(string $caseId, array $userIds): array {
		return array_values(
			array_filter(
				$userIds,
				function (string $uid) use ($caseId): bool {
					$user = $this->userManager->get($uid);
					return $user !== null && $this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === true;
				}
			)
		);
	}//end caseReaders()

	/**
	 * Read the decoded JSON request body.
	 *
	 * Nextcloud's AppFramework auto-decodes a JSON request body and merges
	 * it into the request params, exposed via the PUBLIC getParams(). The
	 * raw getContent() accessor is PROTECTED on OC\AppFramework\Http\Request
	 * and calling it from a controller raises a fatal "Call to protected
	 * method" (HTTP 500) — see StatusTransitionController::readJsonBody()
	 * for the original regression this mirrors the fix of.
	 *
	 * @return array<string, mixed> Decoded payload or empty array
	 */
	private function readJsonBody(): array {
		return $this->request->getParams();
	}//end readJsonBody()
}//end class
