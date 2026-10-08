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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\External\Zgw\NotePush;
use OCA\Dossiq\Service\MentionNotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
	 * @param NotePush $notePush Pushes a note to the neighbouring ZGW register, or says why it did not
	 * @param CaseAccessGuard $caseAccessGuard Decides whether the caller may write notes on this case
	 */
	public function __construct(
		IRequest $request,
		private readonly MentionNotificationService $mentionSvc,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly NotePush $notePush,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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

		$data = $this->readJsonBody();
		$note = $data['note'] ?? null;
		if (is_array($note) === false || $note === []) {
			return new JSONResponse(['error' => 'A note is required'], Http::STATUS_BAD_REQUEST);
		}

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
	}//end push()

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
		$register = (string)($data['register'] ?? '');
		$schema = (string)($data['schema'] ?? '');
		$noteId = (string)($data['noteId'] ?? '');
		$mentionedUserIdsRaw = $data['mentionedUserIds'] ?? [];
		$mentionedUserIds = [];
		if (is_array($mentionedUserIdsRaw) === true) {
			$mentionedUserIds = array_values(array_filter(array_map('strval', $mentionedUserIdsRaw)));
		}

		if ($objectId === '' || $mentionedUserIds === []) {
			return new JSONResponse(
				['error' => 'objectId and a non-empty mentionedUserIds array are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$notified = $this->mentionSvc->notifyMention(
				actorUserId: $actor->getUID(),
				actorDisplayName: $actor->getDisplayName(),
				objectId: $objectId,
				register: $register,
				schema: $schema,
				noteId: $noteId,
				mentionedUserIds: $mentionedUserIds,
			);

			return new JSONResponse(['notified' => $notified], Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to dispatch note mention notifications: ' . $e->getMessage(),
				['app' => 'dossiq']
			);
			return new JSONResponse(
				['error' => 'Could not dispatch mention notifications: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end mention()

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
