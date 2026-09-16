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
	 */
	public function __construct(
		private readonly PersonLinkReader $people,
		private readonly DocumentProjectionService $folders,
		private readonly PartyIndicatorReader $indicators,
		private readonly IShareManager $shares,
		private readonly IUserSession $userSession,
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
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function request(string $caseId, string $personUid, string $note = '', int $days = 0): array {
		$person = $this->people->personOn(caseId: $caseId, personUid: $personUid);
		if ($person === null) {
			throw new RuntimeException('This person is not on this case', 404);
		}

		$email = $this->people->emailOf(link: $person);
		if ($email === '') {
			throw new RuntimeException('This person has no email address, so there is nobody to send the request to', 422);
		}

		// The refusal is evaluated HERE, where the message goes out, and not
		// only in the dialog that lists who can be asked. A check that lives
		// in one caller is a check the next caller does not have.
		$refusal = $this->indicators->sendRefusalFor(
			partyUuid: $this->indicators->partyUuidOf(link: $person)
		);
		if ($refusal !== null) {
			throw new RuntimeException(
				'A file request to ' . $this->people->nameOf(link: $person)
				. ' is refused by the indicator "' . $refusal . '" on this party',
				403
			);
		}

		$folder = $this->folders->folderOf(objectId: $caseId);
		if ($folder === null) {
			throw new RuntimeException('This case has no folder yet, so there is nowhere to upload to', 422);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Not authenticated', 401);
		}

		$expires = new DateTime('+' . $this->daysOf(days: $days) . ' days');
		$share = $this->shares->newShare();
		$share->setNode($folder);
		$share->setShareType(IShare::TYPE_EMAIL);
		$share->setSharedWith($email);
		$share->setSharedBy($user->getUID());
		$share->setShareOwner($folder->getOwner()?->getUID() ?? $user->getUID());
		// Create and nothing else: the party may put a file in, and can
		// neither read nor change what is already in the case folder.
		$share->setPermissions(Constants::PERMISSION_CREATE);
		$share->setExpirationDate($expires);
		if ($note !== '') {
			$share->setNote($note);
		}

		try {
			$created = $this->shares->createShare($share);
		} catch (Throwable $e) {
			throw new RuntimeException('The file request could not be sent: ' . $e->getMessage(), 500, $e);
		}

		return [
			'recipient' => $email,
			'recipientName' => $this->people->nameOf(link: $person),
			'token' => (string)$created->getToken(),
			'expiresAt' => $expires->format('Y-m-d'),
		];
	}//end request()

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
