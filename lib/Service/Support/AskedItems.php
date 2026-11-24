<?php

/**
 * Dossiq asked items.
 *
 * The items a handler asks a resident to send: as checklist rows, each not
 * yet received, and as the one-line summary a request is titled by. Blank
 * lines are dropped.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Checklist rows and a summary from a list of asked items.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class AskedItems {

	/**
	 * The item rows a list of asked-for things becomes.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return array<int, array<string, mixed>> The rows, none received yet.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function rows(array $items): array {
		return array_map(
			static fn (string $text): array => ['item' => $text, 'received' => false],
			$this->cleanLines(items: $items)
		);
	}//end rows()

	/**
	 * The asked-for lines, trimmed, without the blank ones.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return array<int, string> The non-blank lines, in order.
	 */
	private function cleanLines(array $items): array {
		$clean = [];
		foreach ($items as $item) {
			$text = trim((string)$item);
			if ($text !== '') {
				$clean[] = $text;
			}
		}

		return $clean;
	}//end cleanLines()

	/**
	 * One line naming what a request is about.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return string The summary.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function summary(array $items): string {
		$clean = $this->cleanLines(items: $items);
		if ($clean === []) {
			return 'Aanvullingsverzoek';
		}

		if (count($clean) === 1) {
			return $clean[0];
		}

		return sprintf('%s and %d more', $clean[0], (count($clean) - 1));
	}//end summary()
}//end class
