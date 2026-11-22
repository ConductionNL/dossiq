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

/**
 * Which party a document came from, and which parties it went to.
 *
 * 🔴 A CORRESPONDENT IS A PARTY OF THE CASE, NEVER A TYPED NAME.
 * `dispatch.contactPersonName` has been in the register since the ZGW import
 * and nothing has ever written it, which is the shape this class refuses. A
 * typed name cannot be counted, cannot be filtered, and goes stale the day
 * the party is corrected: two letters to the same person read as two people.
 * So every value handed in is resolved against the parties the case actually
 * has, and what resolves to nobody is DROPPED rather than stored.
 *
 * 🔴 DROPPED, NOT REFUSED. These values arrive on an upload, and a throw
 * there loses the uploaded file along with the correction. So a bad
 * correspondent leaves the field empty and the document is still saved; the
 * handler fixes it in the properties dialog, with the file already on the
 * case. The one exception a caller may want is visible in the return: an
 * empty result where a value went in means nothing matched.
 *
 * Three ways to resolve, in this order, because each is exact and none is a
 * guess: the party uuid, the contact uid of a link written before the party
 * model, and the e-mail address. The third is the one the mail intake uses,
 * matched case-insensitively on the address and never on a display name.
 *
 * NOTHING HERE CREATES A PARTY. An address nobody on the case holds leaves
 * the sender empty. Creating a party per stranger who mails the case is
 * `contacts-domain`'s act, and doing it here would fill the People tab with
 * one row per newsletter.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
class DocumentCorrespondents {

	/**
	 * The role a party holds when it sent the document.
	 *
	 * The case schema's own link role, so a dispatch and the People tab name
	 * the same thing (see PartyVocabulary::roles()).
	 */
	public const ROLE_SENDER = 'afzender';

	/**
	 * The role a party holds when the document was addressed to it.
	 */
	public const ROLE_RECIPIENT = 'geadresseerde';

	/**
	 * The direction of a document that came in.
	 */
	public const DIRECTION_INCOMING = 'incoming';

	/**
	 * The direction of a document that went out.
	 */
	public const DIRECTION_OUTGOING = 'outgoing';

	/**
	 * The direction of a document that stayed inside.
	 */
	public const DIRECTION_INTERNAL = 'internal';

	/**
	 * The correspondents a document may carry, given its direction.
	 *
	 * An outgoing document names recipients and no sender; an incoming one
	 * names a sender and no recipients; an internal one may name either,
	 * because a note shared with a party is neither a letter nor a reply.
	 *
	 * @param string $direction One of the schema's three directions.
	 *
	 * @return array{sender: bool, recipients: bool} Which fields are allowed.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-012-direction-decides-which-correspondent-a-document-may-carry
	 */
	public function allowedFor(string $direction): array {
		return match (trim($direction)) {
			self::DIRECTION_OUTGOING => ['sender' => false, 'recipients' => true],
			self::DIRECTION_INCOMING => ['sender' => true, 'recipients' => false],
			default => ['sender' => true, 'recipients' => true],
		};
	}//end allowedFor()

	/**
	 * The correspondents to store, resolved against the parties of the case.
	 *
	 * @param mixed $sender The submitted sender: a uuid, a contact uid, an address, or anything else.
	 * @param mixed $recipients The submitted recipients: a list of the same, or one value.
	 * @param array<int, array<string, mixed>> $parties The party links of the case.
	 * @param string $direction The document's direction.
	 *
	 * @return array{sender: string, recipients: array<int, string>} What may be stored.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function resolve(mixed $sender, mixed $recipients, array $parties, string $direction): array {
		$allowed = $this->allowedFor(direction: $direction);
		$index = new CasePartyIndex(parties: $parties);

		$one = '';
		if ($allowed['sender'] === true) {
			$one = $index->match(value: $sender);
		}

		$many = [];
		if ($allowed['recipients'] === true) {
			$many = $index->matchAll(values: $recipients);
		}

		return ['sender' => $one, 'recipients' => $many];
	}//end resolve()

	/**
	 * The party of the case holding an e-mail address, '' when nobody does.
	 *
	 * What the mail intake asks: the From header is an address, and the
	 * sender of the document it files is whichever party already holds it.
	 *
	 * @param string $address The address, in any casing.
	 * @param array<int, array<string, mixed>> $parties The party links of the case.
	 *
	 * @return string The party's identifier, or ''.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function partyHolding(string $address, array $parties): string {
		return (new CasePartyIndex(parties: $parties))->match(value: $address);
	}//end partyHolding()

	/**
	 * What to call a stored correspondent on screen.
	 *
	 * The party's own display name, its contact uid when it has none, and
	 * the identifier itself as the last resort. Never empty: an identifier
	 * nobody can read is still something a handler can ask about, and a
	 * blank cell is not.
	 *
	 * @param string $identifier The stored value.
	 * @param array<int, array<string, mixed>> $parties The party links of the case.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function nameOf(string $identifier, array $parties): string {
		$wanted = trim($identifier);
		if ($wanted === '') {
			return '';
		}

		$party = $this->partyIdentifiedAs(identifier: $wanted, parties: $parties);
		if ($party === []) {
			return $wanted;
		}

		$name = trim((string)($party['displayName'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return trim((string)($party['contactUid'] ?? $wanted));
	}//end nameOf()

	/**
	 * The first party link stored under an identifier, empty when none is.
	 *
	 * @param string                           $identifier The trimmed, non-empty identifier.
	 * @param array<int, array<string, mixed>> $parties    The party links of the case.
	 *
	 * @return array<string, mixed> The party link.
	 */
	private function partyIdentifiedAs(string $identifier, array $parties): array {
		foreach ($parties as $party) {
			if (is_array($party) === true && $this->identifierOf(party: $party) === $identifier) {
				return $party;
			}
		}

		return [];
	}//end partyIdentifiedAs()

	/**
	 * A document's correspondents, named for a reader.
	 *
	 * The stored identifiers stay on the row beside the names. A list filters
	 * and groups on the identifier and renders the name, and handing it only
	 * names would make two parties with the same display name one party.
	 *
	 * @param array<string, mixed> $document The document row.
	 * @param array<int, array<string, mixed>> $parties The party links of the case.
	 *
	 * @return array{senderName: string, recipientNames: array<int, string>} The names.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function describe(array $document, array $parties): array {
		$recipients = [];
		foreach ($this->storedRecipients(document: $document) as $identifier) {
			$recipients[] = $this->nameOf(identifier: $identifier, parties: $parties);
		}

		return [
			'senderName' => $this->nameOf(
				identifier: trim((string)($document['sender'] ?? '')),
				parties: $parties,
			),
			'recipientNames' => $recipients,
		];
	}//end describe()

	/**
	 * Whether a document names one party, as sender or as addressee.
	 *
	 * The filter behind "show me everything that went to this person". An
	 * empty identifier matches everything, which is what no filter means.
	 *
	 * @param array<string, mixed> $document The document row.
	 * @param string $identifier The party's identifier.
	 *
	 * @return bool True when the document names that party.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function mentions(array $document, string $identifier): bool {
		$wanted = trim($identifier);
		if ($wanted === '') {
			return true;
		}

		if (trim((string)($document['sender'] ?? '')) === $wanted) {
			return true;
		}

		return in_array($wanted, $this->storedRecipients(document: $document), true);
	}//end mentions()

	/**
	 * The recipients a stored document carries, as a list of identifiers.
	 *
	 * A document written before this change has no `recipients` at all, and a
	 * schema that dropped the property answers null rather than an empty
	 * array. Both read as none.
	 *
	 * @param array<string, mixed> $document The document row.
	 *
	 * @return array<int, string> The identifiers.
	 */
	private function storedRecipients(array $document): array {
		$stored = ($document['recipients'] ?? []);
		if (is_array($stored) === false) {
			return [];
		}

		$identifiers = [];
		foreach ($stored as $entry) {
			$identifier = $this->storedIdentifier(entry: $entry);
			if ($identifier !== '' && in_array($identifier, $identifiers, true) === false) {
				$identifiers[] = $identifier;
			}
		}

		return $identifiers;
	}//end storedRecipients()

	/**
	 * One stored recipient entry as a trimmed identifier, '' when it is not text or a number.
	 *
	 * @param mixed $entry The stored entry.
	 *
	 * @return string The identifier, or ''.
	 */
	private function storedIdentifier(mixed $entry): string {
		if (is_string($entry) === false && is_numeric($entry) === false) {
			return '';
		}

		return trim((string)$entry);
	}//end storedIdentifier()

	/**
	 * The identifier a party link is stored under: its party uuid, else its
	 * contact uid.
	 *
	 * A link written before the party model has no `partyUuid`, and those
	 * links are on real cases. Refusing them would make the sender of every
	 * document on an older case unfillable.
	 *
	 * @param array<string, mixed> $party The party link.
	 *
	 * @return string The identifier, or ''.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function identifierOf(array $party): string {
		return (new CasePartyIndex(parties: []))->identifierOf(party: $party);
	}//end identifierOf()
}//end class
