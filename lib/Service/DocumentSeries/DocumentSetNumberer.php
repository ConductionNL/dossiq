<?php

/**
 * Dossiq Document Set Numberer.
 *
 * Gives the documents in one set a stable order and a number: the order a
 * series configures, numbered 1..n or keeping the numbers already given, as
 * a court dossier, an inventory list or an export needs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DocumentSeries
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
 * @spec openspec/specs/numbered-document-series/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DocumentSeries;

/**
 * Numbers a set of documents in the order its series configures.
 *
 * THE SIBLING OF DocumentSeriesNumberer. That class hands out one number at a
 * time from a durable counter, per organisation and year, for a document that
 * leaves the building. This one numbers a whole SET at once (the documents of
 * one dossier, one inventory, one export), so the same input always prints the
 * same numbers. Neither knows what kind of document it numbers.
 *
 * STABLE MEANS THREE THINGS. Documents that compare equal on every order key
 * keep the order they came in, so a second run cannot swap them. A document
 * missing an order value sorts after every document that has one. And under
 * the `keep` gap policy a document that already carries a number keeps it,
 * whatever the order says, because a number a reader has cited must still
 * point at the same document.
 *
 * @spec openspec/specs/numbered-document-series/spec.md
 */
class DocumentSetNumberer {

	/**
	 * Number a set of documents.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents, in the order they were read.
	 * @param SeriesDefinition $definition The series' configuration.
	 *
	 * @return array<int, array{sequence: int, number: string, document: array<string, mixed>}> The documents in
	 *                                                                                          number order.
	 *
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public function number(array $documents, SeriesDefinition $definition): array {
		$ordered = $this->order(documents: array_values($documents), keys: $definition->order);

		$sequences = $this->renumber(count: count($ordered));
		if ($definition->gapPolicy === SeriesDefinition::GAP_KEEP) {
			$sequences = $this->keep(ordered: $ordered, field: $definition->numberField);
		}

		$entries = [];
		foreach ($ordered as $index => $document) {
			$sequence = $sequences[$index];
			$entries[] = [
				'sequence' => $sequence,
				'number' => $this->format(sequence: $sequence, definition: $definition),
				'document' => $document,
			];
		}

		usort($entries, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

		return $entries;
	}//end number()

	/**
	 * A filename that sorts the way the set is numbered, e.g. `01-first-decision.pdf`.
	 *
	 * @param int $sequence The document's sequence.
	 * @param int $width The zero-padded width.
	 * @param string $title The document's title.
	 * @param string $source The document's source URI or path, for its extension.
	 * @param string $defaultExtension The extension when the source carries none.
	 *
	 * @return string The filename.
	 *
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public function filename(int $sequence, int $width, string $title, string $source, string $defaultExtension = 'pdf'): string {
		$slug = trim((preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? ''), '-');
		if ($slug === '') {
			$slug = 'document';
		}

		$extension = '';
		if ($source !== '') {
			$path = (string)(parse_url($source, PHP_URL_PATH) ?? $source);
			$extension = (preg_replace('/[^a-z0-9]/', '', strtolower((string)pathinfo($path, PATHINFO_EXTENSION))) ?? '');
		}

		if ($extension === '') {
			$extension = $defaultExtension;
		}

		return sprintf('%0' . $width . 'd-%s.%s', $sequence, $slug, $extension);
	}//end filename()

	/**
	 * Sort by the order keys, stably, missing values last.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents, input order.
	 * @param array<int, array{field: string, direction: string}> $keys The order keys.
	 *
	 * @return array<int, array<string, mixed>> The documents, in order, re-indexed.
	 */
	private function order(array $documents, array $keys): array {
		if ($keys === []) {
			return $documents;
		}

		$indexed = [];
		foreach ($documents as $position => $document) {
			$indexed[] = ['position' => $position, 'document' => $document];
		}

		usort(
			$indexed,
			function (array $left, array $right) use ($keys): int {
				foreach ($keys as $key) {
					$compared = $this->compare(
						left: ($left['document'][$key['field']] ?? null),
						right: ($right['document'][$key['field']] ?? null),
						descending: $key['direction'] === 'desc',
					);
					if ($compared !== 0) {
						return $compared;
					}
				}

				return $left['position'] <=> $right['position'];
			}
		);

		return array_column($indexed, 'document');
	}//end order()

	/**
	 * Compare two order values; a missing value sorts last in either direction.
	 *
	 * @param mixed $left The left value.
	 * @param mixed $right The right value.
	 * @param bool $descending Whether the key sorts descending.
	 *
	 * @return int Negative, zero or positive.
	 */
	private function compare(mixed $left, mixed $right, bool $descending): int {
		$leftMissing = ($left === null || $left === '');
		$rightMissing = ($right === null || $right === '');
		if ($leftMissing === true || $rightMissing === true) {
			return ((int)$leftMissing - (int)$rightMissing);
		}

		$result = strcmp((string)$left, (string)$right);
		if (is_numeric($left) === true && is_numeric($right) === true) {
			$result = ((float)$left <=> (float)$right);
		}

		if ($descending === true) {
			return -$result;
		}

		return $result;
	}//end compare()

	/**
	 * Sequences 1..n.
	 *
	 * @param int $count How many documents.
	 *
	 * @return array<int, int> Sequence per position.
	 */
	private function renumber(int $count): array {
		if ($count === 0) {
			return [];
		}

		return range(1, $count);
	}//end renumber()

	/**
	 * Keep each stored number; give the rest the next numbers after the highest, in order.
	 *
	 * A number held by two documents is kept by the first in order only: the
	 * second is treated as unnumbered, so no number is ever printed twice.
	 *
	 * @param array<int, array<string, mixed>> $ordered The documents, in order.
	 * @param string $field Where a kept number is stored.
	 *
	 * @return array<int, int> Sequence per position.
	 */
	private function keep(array $ordered, string $field): array {
		$sequences = [];
		$taken = [];
		foreach ($ordered as $index => $document) {
			$stored = ($document[$field] ?? null);
			if (is_numeric($stored) === true && (int)$stored >= 1 && (string)(int)$stored === (string)$stored
				&& isset($taken[(int)$stored]) === false
			) {
				$sequences[$index] = (int)$stored;
				$taken[(int)$stored] = true;
			}
		}

		$next = 1;
		if ($taken !== []) {
			$next = (max(array_keys($taken)) + 1);
		}

		foreach (array_keys($ordered) as $index) {
			if (isset($sequences[$index]) === false) {
				$sequences[$index] = $next;
				$next++;
			}
		}

		return $sequences;
	}//end keep()

	/**
	 * The printed number: `<prefix>-<padded>` or just the padded sequence.
	 *
	 * @param int $sequence The sequence.
	 * @param SeriesDefinition $definition The series.
	 *
	 * @return string The printed number.
	 */
	private function format(int $sequence, SeriesDefinition $definition): string {
		$padded = sprintf('%0' . $definition->width . 'd', $sequence);
		if ($definition->prefix === '') {
			return $padded;
		}

		return $definition->prefix . '-' . $padded;
	}//end format()
}//end class
