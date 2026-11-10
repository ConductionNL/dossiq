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

namespace OCA\Dossiq\Service\People;

use DateTime;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IUser;
use OCA\Dossiq\Service\Pipelinq\PartyRefusalReader;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use RuntimeException;
use Throwable;

/**
 * Asks a party of a case for a file.
 *
 * A file request is an email share of the case folder that may create and
 * nothing else: the party gets a link, what they upload lands in the case
 * folder, and the document projection makes it a document of the case. The
 * recipient is a person linked to the case, never a typed address, because
 * naming the party is the whole point.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
class FileRequestService {

	/**
	 * How long a request stands when the caller names no expiry.
	 */
	public const DEFAULT_DAYS = 14;

	/**
	 * @param PersonLinkReader $people The people on the case.
	 * @param DocumentProjectionService $folders The case's own folder.
	 * @param PartyIndicatorReader $indicators What the party's indicators refuse.
	 * @param IShareManager $shares Creates the share Nextcloud mails.
	 * @param IUserSession $userSession The handler making the request.
	 * @param PartyRefusalReader|null $refusals Joins pipelinq's refusal to this app's own.
	 */
	public function __construct(
		private readonly PersonLinkReader $people,
		private readonly DocumentProjectionService $folders,
		private readonly PartyIndicatorReader $indicators,
		private readonly IShareManager $shares,
		private readonly IUserSession $userSession,
		// Nullable and last: pipelinq is optional, and every existing
		// construction of this service keeps working unchanged.
		private readonly ?PartyRefusalReader $refusals = null,
	) {
	}//end __construct()

	/**
	 * Ask one party of a case for a file.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $personUid The party's uid, as the case's people list names them.
	 * @param string $note What the party is asked for.
	 * @param int $days How long the request stands, 0 for the default.
	 *
	 * @return array<string, mixed> The recipient, the token and when it expires.
	 *
	 * @throws RuntimeException 404 when the person is not on the case, 403 when an
	 *         indicator on them refuses the send, 422 when they have no address or
	 *         the case has no folder.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function request(string $caseId, string $personUid, string $note = '', int $days = 0): array {
		$person = $this->people->personOn(caseId: $caseId, personUid: $personUid);
		if ($person === null) {
			throw new RuntimeException('This person is not on this case', 404);
		}

		$request = new UploadRequest();
		$request->email = $this->addressOf(person: $person);
		$this->refuseWhenIndicated(person: $person);
		$request->folder = $this->caseFolder(caseId: $caseId);
		$request->user = $this->caller();
		$request->expires = new DateTime('+' . $this->daysOf(days: $days) . ' days');
		$request->note = $note;

		$created = $this->createdShare(share: $this->uploadShare(request: $request));

		return [
			'recipient' => $request->email,
			'recipientName' => $this->people->nameOf(link: $person),
			'token' => (string)$created->getToken(),
			'expiresAt' => $request->expires->format('Y-m-d'),
		];
	}//end request()

	/**
	 * The person's address, or a refusal when there is none.
	 *
	 * @param array<string, mixed> $person The person on the case.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the person has no address (422).
	 */
	private function addressOf(array $person): string {
		$email = $this->people->emailOf(link: $person);
		if ($email === '') {
			throw new RuntimeException('This person has no email address, so there is nobody to send the request to', 422);
		}

		return $email;
	}//end addressOf()

	/**
	 * Refuse a request the party's indicator refuses.
	 *
	 * The refusal is evaluated HERE, where the message goes out, and not
	 * only in the dialog that lists who can be asked. A check that lives
	 * in one caller is a check the next caller does not have.
	 *
	 * @param array<string, mixed> $person The person on the case.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an indicator refuses the send (403).
	 */
	private function refuseWhenIndicated(array $person): void {
		$partyUuid = $this->indicators->partyUuidOf(link: $person);

		$refusal = $this->refusalFor(partyUuid: $partyUuid);

		if ($refusal !== null && $refusal !== '') {
			throw new RuntimeException(
				'A file request to ' . $this->people->nameOf(link: $person)
				. ' is refused by the indicator "' . $refusal . '" on this party',
				403
			);
		}
	}//end refuseWhenIndicated()

	/**
	 * The case folder, or a refusal when the case has none yet.
	 *
	 * @param string $caseId The case.
	 *
	 * @return Folder
	 *
	 * @throws RuntimeException When there is no folder (422).
	 */
	private function caseFolder(string $caseId): Folder {
		$folder = $this->folders->folderOf(objectId: $caseId);
		if ($folder === null) {
			throw new RuntimeException('This case has no folder yet, so there is nowhere to upload to', 422);
		}

		return $folder;
	}//end caseFolder()

	/**
	 * The signed-in caller, or a refusal.
	 *
	 * @return IUser
	 *
	 * @throws RuntimeException When nobody is signed in (401).
	 */
	private function caller(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Not authenticated', 401);
		}

		return $user;
	}//end caller()

	/**
	 * The create-only email share of the case folder.
	 *
	 * Create and nothing else: the party may put a file in, and can neither
	 * read nor change what is already in the case folder.
	 *
	 * @param UploadRequest $request The checked request.
	 *
	 * @return IShare
	 */
	private function uploadShare(UploadRequest $request): IShare {
		$share = $this->shares->newShare();
		$share->setNode($request->folder);
		$share->setShareType(IShare::TYPE_EMAIL);
		$share->setSharedWith($request->email);
		$share->setSharedBy($request->user->getUID());
		$share->setShareOwner($request->folder->getOwner()?->getUID() ?? $request->user->getUID());
		$share->setPermissions(Constants::PERMISSION_CREATE);
		$share->setExpirationDate($request->expires);
		if ($request->note !== '') {
			$share->setNote($request->note);
		}

		return $share;
	}//end uploadShare()

	/**
	 * Create the share; a refusal of the share manager is reported.
	 *
	 * @param IShare $share The share.
	 *
	 * @return IShare The created share.
	 *
	 * @throws RuntimeException When the share could not be created (500).
	 */
	private function createdShare(IShare $share): IShare {
		try {
			return $this->shares->createShare($share);
		} catch (Throwable $e) {
			throw new RuntimeException('The file request could not be sent: ' . $e->getMessage(), 500, $e);
		}
	}//end createdShare()

	/**
	 * The indicator refusing a send to this party, or null when nothing refuses.
	 *
	 * EITHER REFUSAL STOPS IT. `PartyRefusalReader` joins pipelinq's blocking
	 * answer to the one this app already read out of OpenRegister, and
	 * pipelinq's label wins the sentence when both refuse because it carries
	 * the vocabulary an administrator maintains. With pipelinq absent the
	 * reader is not there, and the answer comes from OpenRegister alone, which
	 * is exactly what this call site did before.
	 *
	 * @param string $partyUuid The party the request would go to.
	 *
	 * @return string|null The refusing indicator, or null.
	 *
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	private function refusalFor(string $partyUuid): ?string {
		if ($this->refusals === null) {
			return $this->indicators->sendRefusalFor(partyUuid: $partyUuid);
		}

		$verdict = $this->refusals->maySendTo(partyUuid: $partyUuid);
		if ($verdict['refused'] === true) {
			return $verdict['indicator'];
		}

		return null;
	}//end refusalFor()

	/**
	 * How many days a request stands: what the caller asked, within a year.
	 *
	 * @param int $days What the caller asked for.
	 *
	 * @return int The days.
	 */
	private function daysOf(int $days): int {
		if ($days < 1) {
			return self::DEFAULT_DAYS;
		}

		return min($days, 365);
	}//end daysOf()
}//end class
