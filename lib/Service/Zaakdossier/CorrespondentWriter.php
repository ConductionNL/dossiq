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

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * The one place a document's correspondents are written.
 *
 * Two things happen together and never apart. The document gets `sender` and
 * `recipients`, which is what every surface reads, because those sit on the
 * row a list is already showing. And each correspondent gets a `dispatch`
 * row, which is what carries the date of ONE send to ONE party: a letter sent
 * twice to the same addressee is one recipient and two dispatches, and a
 * field on the document cannot say that.
 *
 * 🔴 THE FIELDS AND THE ROWS ARE WRITTEN IN ONE CALL ON PURPOSE. Two entry
 * points is how a document ends up naming an addressee with no dispatch
 * behind it, or a dispatch pointing at a party the document no longer names.
 * Callers hand in what they know and this decides the rest.
 *
 * 🔴 A FAILED DISPATCH WRITE DOES NOT LOSE THE FIELDS. The fields are the
 * answer to "who was this for"; the dispatch row is the audit of one send.
 * If OpenRegister refuses the second, the caller still gets the first, and
 * the failure is logged rather than thrown, because the act that triggered
 * this was filing a document and that document is already on the case.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */
class CorrespondentWriter {
	use SearchesObjects;

	/**
	 * @param DocumentCorrespondents $rules Which party a submitted value names.
	 * @param PersonLinkReader $people The parties of a case, read from OpenRegister.
	 * @param SettingsService $settingsService OpenRegister access and the dispatch schema.
	 */
	public function __construct(
		private readonly DocumentCorrespondents $rules,
		private readonly PersonLinkReader $people,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The correspondents a document may carry, resolved against its case.
	 *
	 * Read-only: nothing is stored. The caller merges the answer into the
	 * record it is about to save, so an upload makes one write and not two.
	 *
	 * @param string $caseId The case the document is on.
	 * @param mixed $sender The submitted sender.
	 * @param mixed $recipients The submitted recipients.
	 * @param string $direction The document's direction.
	 *
	 * @return array{sender: string, recipients: array<int, string>} What may be stored.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function resolveFor(string $caseId, mixed $sender, mixed $recipients, string $direction): array {
		return $this->rules->resolve(
			sender: $sender,
			recipients: $recipients,
			parties: $this->partiesOf(caseId: $caseId),
			direction: $direction,
		);
	}//end resolveFor()

	/**
	 * The party of a case holding an e-mail address, '' when nobody does.
	 *
	 * @param string $caseId The case.
	 * @param string $address The address, in any casing.
	 *
	 * @return string The party's identifier, or ''.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function partyHolding(string $caseId, string $address): string {
		if (trim($address) === '') {
			return '';
		}

		return $this->rules->partyHolding(address: $address, parties: $this->partiesOf(caseId: $caseId));
	}//end partyHolding()

	/**
	 * Record one dispatch per correspondent of a document.
	 *
	 * Idempotent per party and role: a document saved twice with the same
	 * addressee keeps one row, because the second save is a correction and
	 * not a second send. A genuinely repeated send names its own date and is
	 * the caller's to record.
	 *
	 * @param string $caseId The case.
	 * @param string $documentId The informatieobject uuid.
	 * @param array{sender: string, recipients: array<int, string>} $correspondents What the document names.
	 *
	 * @return int How many dispatch rows were created.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function recordDispatches(string $caseId, string $documentId, array $correspondents): int {
		if (trim($documentId) === '') {
			return 0;
		}

		$wanted = [];
		$sender = trim((string)($correspondents['sender'] ?? ''));
		if ($sender !== '') {
			$wanted[] = [$sender, DocumentCorrespondents::ROLE_SENDER];
		}

		foreach ((array)($correspondents['recipients'] ?? []) as $recipient) {
			$party = trim((string)$recipient);
			if ($party !== '') {
				$wanted[] = [$party, DocumentCorrespondents::ROLE_RECIPIENT];
			}
		}

		if ($wanted === []) {
			return 0;
		}

		$written = 0;
		foreach ($wanted as [$party, $role]) {
			if ($this->writeDispatch(caseId: $caseId, documentId: $documentId, party: $party, role: $role) === true) {
				$written++;
			}
		}

		return $written;
	}//end recordDispatches()

	/**
	 * The party links of a case, [] when OpenRegister cannot answer.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The links.
	 */
	private function partiesOf(string $caseId): array {
		if (trim($caseId) === '') {
			return [];
		}

		return $this->people->peopleOn(caseId: $caseId);
	}//end partiesOf()

	/**
	 * Write one dispatch row, unless the document already has that one.
	 *
	 * @param string $caseId The case.
	 * @param string $documentId The informatieobject uuid.
	 * @param string $party The party's identifier.
	 * @param string $role `afzender` or `geadresseerde`.
	 *
	 * @return bool True when a row was created.
	 */
	private function writeDispatch(string $caseId, string $documentId, string $party, string $role): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dispatch_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			$existing = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: [
					'document' => $documentId,
					'involvedParty' => $party,
					'relationshipType' => $role,
					'_limit' => 1,
				],
			);
			if ($existing !== []) {
				return false;
			}

			$row = [
				'document' => $documentId,
				'case' => $caseId,
				'involvedParty' => $party,
				'relationshipType' => $role,
			];
			// The date says which act this row records, and only one of the
			// two is true: a sender was received from, an addressee was sent
			// to. Writing both would make every dispatch read as a round trip.
			if ($role === DocumentCorrespondents::ROLE_SENDER) {
				$row['receiveDate'] = date('Y-m-d');
			}

			if ($role === DocumentCorrespondents::ROLE_RECIPIENT) {
				$row['sendDate'] = date('Y-m-d');
			}

			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $row,
			);
		} catch (Throwable) {
			// The document is already on the case and already names the
			// party; a missing audit row is not worth losing that write.
			return false;
		}//end try

		return true;
	}//end writeDispatch()
}//end class
