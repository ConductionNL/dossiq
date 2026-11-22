<?php

/**
 * The parties of a case, indexed by every way a submitted value may name one.
 *
 * A submitted sender or recipient is a party uuid, a contact uid, an e-mail
 * address in any casing, or a picker's option object carrying one of those.
 * This index answers which party of the case such a value names, without a
 * store; the first party holding a key wins.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * Matches submitted values to the parties of one case.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CasePartyIndex {

	/**
	 * Lowercased key to the identifier of the party it names.
	 *
	 * @var array<string, string>
	 */
	private readonly array $index;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $parties The party links of the case.
	 *
	 * @return void
	 */
	public function __construct(array $parties) {
		$this->index = $this->indexOf(parties: $parties);
	}//end __construct()

	/**
	 * One submitted value as the identifier of a party of the case, or ''.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return string The identifier, or '' when nothing matched.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function match(mixed $value): string {
		// A picker hands back the option object it was given, not its value.
		if (is_array($value) === true) {
			return $this->matchOption(option: $value);
		}

		$wanted = '';
		if (is_string($value) === true || is_numeric($value) === true) {
			$wanted = mb_strtolower(trim((string)$value));
		}

		if ($wanted === '') {
			return '';
		}

		return ($this->index[$wanted] ?? '');
	}//end match()

	/**
	 * The submitted recipients as identifiers of parties of the case, each once, unmatched ones left out.
	 *
	 * @param mixed $values The submitted values: a list, or one value.
	 *
	 * @return array<int, string> The identifiers.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function matchAll(mixed $values): array {
		$many = [];
		foreach ($this->asList(value: $values) as $candidate) {
			$match = $this->match(value: $candidate);
			if ($match !== '' && in_array($match, $many, true) === false) {
				$many[] = $match;
			}
		}

		return $many;
	}//end matchAll()

	/**
	 * The identifier a party link is stored under: its party uuid, else its contact uid.
	 *
	 * @param array<string, mixed> $party The party link.
	 *
	 * @return string The identifier, or ''.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 * @spec openspec/changes/method-decomposition/tasks.md
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
			if (is_array($party) === true) {
				$index = $this->withKeysOf(index: $index, party: $party);
			}
		}

		return $index;
	}//end indexOf()

	/**
	 * The index with one party's names added: its identifier, contact uid and address, first holder wins.
	 *
	 * @param array<string, string> $index The index so far.
	 * @param array<string, mixed>  $party The party link.
	 *
	 * @return array<string, string> Lowercased key to identifier.
	 */
	private function withKeysOf(array $index, array $party): array {
		$identifier = $this->identifierOf(party: $party);
		if ($identifier === '') {
			return $index;
		}

		foreach ([$identifier, (string)($party['contactUid'] ?? ''), (string)($party['email'] ?? '')] as $key) {
			$key = mb_strtolower(trim($key));
			if ($key !== '' && isset($index[$key]) === false) {
				$index[$key] = $identifier;
			}
		}

		return $index;
	}//end withKeysOf()

	/**
	 * A picker's option object as the identifier of a party of the case, or ''.
	 *
	 * @param array<string, mixed> $option The option object.
	 *
	 * @return string The identifier of the first key that matches, or ''.
	 */
	private function matchOption(array $option): string {
		foreach (['partyUuid', 'id', 'contactUid', 'email'] as $key) {
			$candidate = mb_strtolower(trim((string)($option[$key] ?? '')));
			if ($candidate !== '' && isset($this->index[$candidate]) === true) {
				return $this->index[$candidate];
			}
		}

		return '';
	}//end matchOption()

	/**
	 * A submitted recipients value as a list, whatever shape it arrived in.
	 *
	 * @param mixed $value One value, a list of them, or nothing.
	 *
	 * @return array<int, mixed> The candidates.
	 */
	private function asList(mixed $value): array {
		if (is_array($value) === false) {
			return $this->singleAsList(value: $value);
		}

		// An associative array is ONE option object, not a list of them.
		if ($value !== [] && array_is_list($value) === false) {
			return [$value];
		}

		return $value;
	}//end asList()

	/**
	 * One submitted value that is not an array as a list: empty for nothing, else the value alone.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, mixed> The candidates.
	 */
	private function singleAsList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		return [$value];
	}//end singleAsList()
}//end class
