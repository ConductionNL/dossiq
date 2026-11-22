<?php

/**
 * A case type's status rows, read for an ending act.
 *
 * What the ending acts read off the status rows a case type declares,
 * without a store: a row's id whichever shape it came back in, whether it
 * is terminal, the rows in process order (an unordered status last), the
 * terminal status an act lands on, and the phases a case skips when it is
 * ended early.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\Support\LanguageMapText;

/**
 * Reads status rows: their ids, which are final, and their process order.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class StatusRows {

	/**
	 * Constructor.
	 *
	 * @param LanguageMapText $text A translatable status name as one string.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LanguageMapText $text,
	) {
	}//end __construct()

	/**
	 * The terminal status an act lands on: the one named when it is terminal, else the first in process order.
	 *
	 * @param list<array<string, mixed>> $terminal The terminal rows, at least one.
	 * @param string                     $named    The status the caller named, or ''.
	 *
	 * @return string The status id.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function namedTerminal(array $terminal, string $named): string {
		foreach ($terminal as $row) {
			if ($this->idOf(row: $row) === $named && $named !== '') {
				return $named;
			}
		}

		usort($terminal, fn (array $left, array $right): int => $this->orderOf(row: $left) <=> $this->orderOf(row: $right));

		return $this->idOf(row: $terminal[0]);
	}//end namedTerminal()

	/**
	 * The phases between the status a case leaves and the terminal one it lands on, by name.
	 *
	 * @param array<int, array<string, mixed>> $rows       The case type's status rows.
	 * @param string                           $fromStatus The status the case is leaving.
	 * @param string                           $toStatus   The terminal status it lands on.
	 *
	 * @return list<string> The skipped phase names, in process order.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function skippedNames(array $rows, string $fromStatus, string $toStatus): array {
		usort($rows, fn (array $left, array $right): int => $this->orderOf(row: $left) <=> $this->orderOf(row: $right));

		$seen = false;
		$skipped = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			if ($id === $fromStatus) {
				$seen = true;
				continue;
			}

			if ($id === $toStatus) {
				break;
			}

			if ($seen === true && $this->isFinal(row: $row) === false) {
				$skipped[] = $this->text->textOr(value: ($row['name'] ?? ($row['title'] ?? null)), fallback: $id);
			}
		}

		return $skipped;
	}//end skippedNames()

	/**
	 * Whether a statusType row is terminal.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return bool True when it carries isFinal.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isFinal(array $row): bool {
		return in_array(($row['isFinal'] ?? false), [true, 1, '1', 'true'], true);
	}//end isFinal()

	/**
	 * A statusType row's place in the process.
	 *
	 * An unordered status sorts last rather than first, the same reading the
	 * stages stepper uses: a status nobody placed in the process is not step
	 * one.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return int The order.
	 */
	private function orderOf(array $row): int {
		$order = ($row['order'] ?? null);
		if (is_numeric($order) === false) {
			return PHP_INT_MAX;
		}

		return (int)$order;
	}//end orderOf()

	/**
	 * A statusType row's id, whichever shape it came back in.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return string The id.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()
}//end class
