<?php

/**
 * Dossiq case-relation codec.
 *
 * Owns the on-disk shape of the `case.relatedCases` field and every pure
 * operation over a relation list: decoding it (the field is a JSON-encoded
 * string, but an already-decoded array is tolerated because the ZGW inbound
 * mapping layer writes it directly), building a single entry, and the
 * pair-level membership and removal used to keep both sides of a symmetric
 * relation consistent.
 *
 * Split out of CaseRelationService so that service keeps only the policy —
 * which guards fail closed, and that every add/remove touches both cases —
 * while the encoding contract lives in one place. Everything here is pure: no
 * OpenRegister access, no session, no logging.
 *
 * An entry that names no case is dropped rather than raising: a partially
 * written relation must not make the whole list unreadable.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * Encodes, decodes and edits the typed peer-relation list of a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationCodec {
	/**
	 * The case property that holds each `aardRelatie` as a real reference.
	 *
	 * `relatedCases` is one JSON-encoded string, so OpenRegister sees no
	 * reference in it at all: `scanForRelations()` walks structure, not the
	 * inside of a string, and a property with no `$ref` is not a reference
	 * property to `RelationAnnotationValidator::isReferenceProperty()` either.
	 * A link stored only there is invisible to `/uses`, to `/used`, to the
	 * relation graph and to its export.
	 *
	 * It is three properties rather than one because OpenRegister resolves one
	 * label pair per property. Three types sharing a property would share a
	 * label, which is the single name for both ends that this change exists to
	 * stop.
	 *
	 * @var array<string, string>
	 */
	public const TYPED_PROPERTIES = [
		'vervolg'  => 'followUpCases',
		'subject'  => 'subjectCases',
		'bijdrage' => 'contributingCases',
	];

	/**
	 * The property that carries one relation type, or null when none does.
	 *
	 * @param string $natureRelationship Relation type (`aardRelatie`).
	 *
	 * @return string|null The case property name.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function typedProperty(string $natureRelationship): ?string {
		return (self::TYPED_PROPERTIES[$natureRelationship] ?? null);
	}//end typedProperty()

	/**
	 * The typed reference lists a case carries, keyed by property name.
	 *
	 * Every typed property is present in the answer, empty when the case has
	 * no link of that type: a caller that has to ask whether the key exists is
	 * a caller that will write a partial list over a full one.
	 *
	 * @param array<string, mixed> $case Case object.
	 *
	 * @return array<string, array<int, string>> Property name to case uuids.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function typedLinks(array $case): array {
		$links = [];
		foreach (self::TYPED_PROPERTIES as $property) {
			$raw = ($case[$property] ?? []);
			if (is_string($raw) === true && $raw !== '') {
				$decoded = json_decode($raw, true);
				$raw     = (is_array($decoded) === true) ? $decoded : [$raw];
			}

			if (is_array($raw) === false) {
				$raw = [];
			}

			$uuids = [];
			foreach ($raw as $value) {
				// A reference list may come back expanded to the referenced
				// object when the caller asked OpenRegister to extend it, so
				// read the uuid out of either shape rather than casting an
				// array to a string.
				if (is_array($value) === true) {
					$value = ($value['id'] ?? ($value['uuid'] ?? ''));
				}

				$value = is_string($value) ? trim($value) : '';
				if ($value !== '' && in_array($value, $uuids, true) === false) {
					$uuids[] = $value;
				}
			}

			$links[$property] = $uuids;
		}//end foreach

		return $links;
	}//end typedLinks()

	/**
	 * The typed lists with one link added, or unchanged when it is already there.
	 *
	 * @param array<string, array<int, string>> $links Typed lists, as {@see self::typedLinks()}.
	 * @param string $natureRelationship Relation type.
	 * @param string $targetId The case being linked to.
	 *
	 * @return array<string, array<int, string>> The typed lists.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function withTypedLink(array $links, string $natureRelationship, string $targetId): array {
		$property = $this->typedProperty(natureRelationship: $natureRelationship);
		if ($property === null || $targetId === '') {
			return $links;
		}

		$current = ($links[$property] ?? []);
		if (in_array($targetId, $current, true) === false) {
			$current[] = $targetId;
		}

		$links[$property] = array_values($current);

		return $links;
	}//end withTypedLink()

	/**
	 * The typed lists with one link removed.
	 *
	 * @param array<string, array<int, string>> $links Typed lists.
	 * @param string|null $natureRelationship Relation type, or null to strip the
	 *                                        case from every typed list.
	 * @param string $targetId The case to unlink.
	 *
	 * @return array<string, array<int, string>> The typed lists.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function withoutTypedLink(array $links, ?string $natureRelationship, string $targetId): array {
		if ($targetId === '') {
			return $links;
		}

		$only = null;
		if ($natureRelationship !== null) {
			$only = $this->typedProperty(natureRelationship: $natureRelationship);
			if ($only === null) {
				return $links;
			}
		}

		foreach ($links as $property => $uuids) {
			if ($only !== null && $property !== $only) {
				continue;
			}

			$links[$property] = array_values(
				array_filter($uuids, static fn (string $uuid): bool => $uuid !== $targetId)
			);
		}

		return $links;
	}//end withoutTypedLink()

	/**
	 * Build a single relation entry, carrying the optional clarification.
	 *
	 * @param string $caseId Referenced case UUID.
	 * @param string $natureRelationship Relation type.
	 * @param string|null $notes Optional free-text clarification.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function buildEntry(string $caseId, string $natureRelationship, ?string $notes): array {
		$entry = ['caseId' => $caseId, 'aardRelatie' => $natureRelationship];
		if ($notes !== null && $notes !== '') {
			$entry['notes'] = $notes;
		}

		return $entry;
	}//end buildEntry()

	/**
	 * Decode the JSON-encoded `relatedCases` field into a list of relation
	 * entries, tolerating an already-array shape.
	 *
	 * @param array<string, mixed> $case Case object.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function decode(array $case): array {
		$entries = [];
		foreach ($this->rawRelationList(case: $case) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$entry = $this->decodeRelationEntry(item: $item);
			if ($entry === null) {
				continue;
			}

			$entries[] = $entry;
		}//end foreach

		return $entries;
	}//end decode()

	/**
	 * Whether a `{caseId, aardRelatie}` pair already exists in a relation list.
	 *
	 * @param array<int, array<string, mixed>> $relations Relation entries.
	 * @param string $caseId Target case UUID.
	 * @param string $natureRelationship Relation type.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function hasPair(array $relations, string $caseId, string $natureRelationship): bool {
		foreach ($relations as $relation) {
			if ((string)($relation['caseId'] ?? '') === $caseId
				&& (string)($relation['aardRelatie'] ?? '') === $natureRelationship
			) {
				return true;
			}
		}

		return false;
	}//end hasPair()

	/**
	 * Return a copy of the relation list with the given pair removed.
	 *
	 * @param array<int, array<string, mixed>> $relations Relation entries.
	 * @param string $caseId Target case UUID.
	 * @param string $natureRelationship Relation type.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function removePair(array $relations, string $caseId, string $natureRelationship): array {
		return array_values(
			array_filter(
				$relations,
				static fn (array $relation): bool => (
					(string)($relation['caseId'] ?? '') !== $caseId
					|| (string)($relation['aardRelatie'] ?? '') !== $natureRelationship
				)
			)
		);
	}//end removePair()

	/**
	 * Return a copy of the relation list with every entry naming a case removed.
	 *
	 * @param array<int, array<string, mixed>> $relations Relation entries.
	 * @param string $caseId Case UUID to strip.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function removeAllForCase(array $relations, string $caseId): array {
		return array_values(
			array_filter(
				$relations,
				static fn (array $relation): bool => (string)($relation['caseId'] ?? '') !== $caseId
			)
		);
	}//end removeAllForCase()

	/**
	 * Read the raw `relatedCases` payload as a list, accepting either the
	 * JSON-encoded string shape or an already-decoded array.
	 *
	 * @param array<string, mixed> $case Case object.
	 *
	 * @return array<mixed> The raw relation list, or [] when unusable.
	 */
	private function rawRelationList(array $case): array {
		$raw = ($case['relatedCases'] ?? null);
		$list = [];
		if (is_array($raw) === true) {
			$list = $raw;
		}

		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				$list = $decoded;
			}
		}

		return $list;
	}//end rawRelationList()

	/**
	 * Normalise one raw relation item into a relation entry.
	 *
	 * @param array<string, mixed> $item Raw relation item.
	 *
	 * @return array<string, string>|null The entry, or null when it names no case.
	 */
	private function decodeRelationEntry(array $item): ?array {
		$targetId = (string)($item['caseId'] ?? '');
		if ($targetId === '') {
			return null;
		}

		$entry = [
			'caseId' => $targetId,
			'aardRelatie' => (string)($item['aardRelatie'] ?? ''),
		];
		if (isset($item['notes']) === true && (string)$item['notes'] !== '') {
			$entry['notes'] = (string)$item['notes'];
		}

		return $entry;
	}//end decodeRelationEntry()
}//end class
