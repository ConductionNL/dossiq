<?php

/**
 * Dossiq case split fields.
 *
 * What a split writes that it can say without a store: the second case's
 * fields taken from the first, its relation back to the first, and the moved
 * items the original records.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;

/**
 * The fields a split writes onto the second case and onto the original.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseSplitFields {

	/**
	 * The second case's fields, taken from the first.
	 *
	 * @param array<string, mixed> $source   The case being split.
	 * @param string               $title    The new case's title.
	 * @param string               $sourceId The uuid of the case being split.
	 *
	 * @return array<string, string> The fields.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function secondCase(array $source, string $title, string $sourceId): array {
		$title = trim($title);
		if ($title === '') {
			$title = 'Split from ' . (string)($source['title'] ?? '');
		}

		return [
			'title' => $title,
			'caseType' => (string)($source['caseType'] ?? ''),
			'assignedGroup' => (string)($source['assignedGroup'] ?? ''),
			'assignee' => (string)($source['assignee'] ?? ''),
			'impact' => (string)($source['impact'] ?? 'medium'),
			'urgency' => (string)($source['urgency'] ?? 'medium'),
			'splitFrom' => $sourceId,
		];
	}//end secondCase()

	/**
	 * The second case's relation back to the first.
	 *
	 * Written as `relatedCases`, a JSON-ENCODED string of typed relations,
	 * because that is what the schema declares and what `CaseRelationCodec`
	 * reads. An array there stores a shape nothing reads and the Related tab
	 * is empty on every split.
	 *
	 * @param string $sourceId The case being split.
	 * @param string $actor    Who split it.
	 *
	 * @return string The encoded relations.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function relationBack(string $sourceId, string $actor): string {
		$relation = [
			'caseId' => $sourceId,
			'aardRelatie' => CaseSplitPlan::RELATION,
			'toelichting' => 'Split from this case by ' . $actor,
		];

		return (string)json_encode([$relation]);
	}//end relationBack()

	/**
	 * The original's earlier moved items followed by these, stamped now.
	 *
	 * @param array<string, mixed>             $case       The case as it was read.
	 * @param array<int, array<string, mixed>> $references The plan's reference entries.
	 *
	 * @return array<int, array<string, mixed>> The moved items.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function movedItems(array $case, array $references): array {
		$now = ['movedAt' => (new DateTimeImmutable())->format('c')];
		$existing = array_filter((array)($case['splitMovedItems'] ?? []), 'is_array');

		return array_merge(
			array_values($existing),
			array_map(static fn (mixed $reference): array => array_merge((array)$reference, $now), array_values($references))
		);
	}//end movedItems()

}//end class
