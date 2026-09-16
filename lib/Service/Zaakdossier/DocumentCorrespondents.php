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
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
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
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-012-direction-decides-which-correspondent-a-document-may-carry
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
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function resolve(mixed $sender, mixed $recipients, array $parties, string $direction): array {
		$allowed = $this->allowedFor(direction: $direction);
		$index = $this->indexOf(parties: $parties);

		$one = '';
		if ($allowed['sender'] === true) {
			$one = $this->matchOne(value: $sender, index: $index);
		}

		$many = [];
		if ($allowed['recipients'] === true) {
			foreach ($this->asList(value: $recipients) as $candidate) {
				$match = $this->matchOne(value: $candidate, index: $index);
				if ($match !== '' && in_array($match, $many, true) === false) {
					$many[] = $match;
				}
			}
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
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function partyHolding(string $address, array $parties): string {
		return $this->matchOne(value: $address, index: $this->indexOf(parties: $parties));
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
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function nameOf(string $identifier, array $parties): string {
		$wanted = trim($identifier);
		if ($wanted === '') {
			return '';
		}

		foreach ($parties as $party) {
			if (is_array($party) === false) {
				continue;
			}

			if ($this->identifierOf(party: $party) !== $wanted) {
				continue;
			}

			$name = trim((string)($party['displayName'] ?? ''));
			if ($name !== '') {
				return $name;
			}

			return trim((string)($party['contactUid'] ?? $wanted));
		}

		return $wanted;
	}//end nameOf()

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
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function identifierOf(array $party): string {
		$uuid = trim((string)($party['partyUuid'] ?? ''));
		if ($uuid !== '') {
			return $uuid;
		}

		return trim((string)($party['contactUid'] ?? ''));
	}//end identifierOf()

	/**
	 * Every way one party of the case may be named, mapped to its identifier.
	 *
	 * @param array<int, array<string, mixed>> $parties The party links.
	 *
	 * @return array<string, string> Lowercased key to identifier.
	 */
	private function indexOf(array $parties): array {
		$index = [];
		foreach ($parties as $party) {
			if (is_array($party) === false) {
				continue;
			}

			$identifier = $this->identifierOf(party: $party);
			if ($identifier === '') {
				continue;
			}

			foreach ([$identifier, (string)($party['contactUid'] ?? ''), (string)($party['email'] ?? '')] as $key) {
				$key = mb_strtolower(trim($key));
				if ($key !== '' && isset($index[$key]) === false) {
					$index[$key] = $identifier;
				}
			}
		}

		return $index;
	}//end indexOf()

	/**
	 * One submitted value as the identifier of a party of the case, or ''.
	 *
	 * @param mixed $value The submitted value.
	 * @param array<string, string> $index The index of the case's parties.
	 *
	 * @return string The identifier, or '' when nothing matched.
	 */
	private function matchOne(mixed $value, array $index): string {
		$wanted = '';
		if (is_string($value) === true || is_numeric($value) === true) {
			$wanted = mb_strtolower(trim((string)$value));
		}

		// A picker hands back the option object it was given, not its value.
		if (is_array($value) === true) {
			foreach (['partyUuid', 'id', 'contactUid', 'email'] as $key) {
				$candidate = mb_strtolower(trim((string)($value[$key] ?? '')));
				if ($candidate !== '' && isset($index[$candidate]) === true) {
					return $index[$candidate];
				}
			}
		}

		if ($wanted === '') {
			return '';
		}

		return ($index[$wanted] ?? '');
	}//end matchOne()

	/**
	 * A submitted recipients value as a list, whatever shape it arrived in.
	 *
	 * @param mixed $value One value, a list of them, or nothing.
	 *
	 * @return array<int, mixed> The candidates.
	 */
	private function asList(mixed $value): array {
		if (is_array($value) === false) {
			if ($value === null || $value === '') {
				return [];
			}

			return [$value];
		}

		// An associative array is ONE option object, not a list of them.
		if ($value !== [] && array_is_list($value) === false) {
			return [$value];
		}

		return $value;
	}//end asList()
}//end class
