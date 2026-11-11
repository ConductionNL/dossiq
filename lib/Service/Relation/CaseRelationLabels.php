<?php

/**
 * What a typed case link is called, from the side you asked from.
 *
 * OpenRegister answers `/uses` and `/used` with a `relation` block per row, and
 * `displayLabel` is already the half of the label pair that belongs to that
 * row's direction (openregister#3764). This reads both directions, keeps that
 * label exactly as it arrives, and hands back one row per link.
 *
 * It computes no label of its own, and that is the whole point. A caller that
 * picks between `label` and `inverseLabel` per direction is a caller that will
 * pick the near one on the reverse panel, which is the defect the openregister
 * change exists to end.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseRelationService} so that service
 * keeps the relation policy: which writes are allowed, and what a delete takes
 * with it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * Reads the typed peer relations of a case with the label each side sees.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationLabels {
	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store OpenRegister reads for case objects.
	 * @param CaseRelationCodec $codec Relation-list encoding.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
	) {
	}//end __construct()

	/**
	 * The typed peer relations OpenRegister can name, keyed by target and type.
	 *
	 * @param string $caseId The case being read.
	 * @param array<int, array<string, mixed>> $stored The case's own
	 *        `relatedCases` entries, which carry the clarification.
	 *
	 * @return array<string, array<string, mixed>> Rows keyed by target and type.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function rowsFor(string $caseId, array $stored): array {
		$run  = new RelationLabelRun(caseId: $caseId, stored: $stored);
		$rows = [];

		foreach ([false, true] as $incoming) {
			foreach ($this->store->relationRows(caseUuid: $caseId, incoming: $incoming) as $row) {
				$entry = $this->entryFor(run: $run, row: $row);
				if ($entry === null) {
					continue;
				}

				$rows[$entry['caseId'].'|'.$entry['aardRelatie']] = $entry;
			}
		}

		return $rows;
	}//end rowsFor()

	/**
	 * One relation row, or null when it is not a typed peer link.
	 *
	 * @param RelationLabelRun     $run The read.
	 * @param array<string, mixed> $row The serialised far case.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function entryFor(RelationLabelRun $run, array $row): ?array {
		$link = self::typedLinkOf(row: $row);
		if ($link === null) {
			return null;
		}

		$relation = $link['relation'];
		$entry = [
			'caseId'       => $link['targetId'],
			'aardRelatie'  => $link['type'],
			'title'        => (string)($row['title'] ?? ''),
			'direction'    => ($relation['direction'] ?? null),
			'label'        => ($relation['label'] ?? null),
			'inverseLabel' => ($relation['inverseLabel'] ?? null),
			'displayLabel' => ($relation['displayLabel'] ?? null),
			'legacy'       => false,
		];

		$notes = $this->noteFor(run: $run, row: $row);
		if ($notes !== null) {
			$entry['notes'] = $notes;
		}

		return $entry;
	}//end entryFor()

	/**
	 * The typed peer link a far case carries: its relation, target and type, or null when it is none.
	 *
	 * @param array<string, mixed> $row The serialised far case.
	 *
	 * @return array{relation: array<string, mixed>, targetId: string, type: string}|null The link.
	 */
	private static function typedLinkOf(array $row): ?array {
		$relation = ($row['relation'] ?? null);
		if (self::isTypedRelation(relation: $relation) === false) {
			return null;
		}

		$targetId = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		$type     = (string)($relation['type'] ?? '');
		if ($targetId === '' || $type === '') {
			return null;
		}

		return ['relation' => $relation, 'targetId' => $targetId, 'type' => $type];
	}//end typedLinkOf()

	/**
	 * Whether a relation is one of the typed peer properties.
	 *
	 * @param mixed $relation The far case's relation.
	 *
	 * @return bool True for a typed peer relation.
	 */
	private static function isTypedRelation(mixed $relation): bool {
		return (is_array($relation) === true
			&& in_array((string)($relation['property'] ?? ''), array_values(CaseRelationCodec::TYPED_PROPERTIES), true) === true);
	}//end isTypedRelation()

	/**
	 * The clarification of a link: this case's own entry first, else the far case's.
	 *
	 * @param RelationLabelRun     $run The read.
	 * @param array<string, mixed> $row The serialised far case, a typed peer link.
	 *
	 * @return string|null The clarification, or null.
	 */
	private function noteFor(RelationLabelRun $run, array $row): ?string {
		$link = (array)self::typedLinkOf(row: $row);
		$near = $this->noteIn(entries: $run->stored, caseId: $link['targetId'], natureRelationship: $link['type']);
		if ($near !== null) {
			return $near;
		}

		return $this->noteIn(
			entries: $this->codec->decode(case: $row),
			caseId: $run->caseId,
			natureRelationship: $link['type']
		);
	}//end noteFor()

	/**
	 * The note one relation list carries for a case and type.
	 *
	 * @param array<int, array<string, mixed>> $entries The relation entries.
	 * @param string $caseId The case the entry names.
	 * @param string $natureRelationship Relation type.
	 *
	 * @return string|null The note.
	 */
	private function noteIn(array $entries, string $caseId, string $natureRelationship): ?string {
		foreach ($entries as $entry) {
			if ((string)($entry['caseId'] ?? '') === $caseId
				&& (string)($entry['aardRelatie'] ?? '') === $natureRelationship
				&& (string)($entry['notes'] ?? '') !== ''
			) {
				return (string)$entry['notes'];
			}
		}

		return null;
	}//end noteIn()
}//end class
