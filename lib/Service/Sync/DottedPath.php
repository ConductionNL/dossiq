<?php

/**
 * A value read or set at a dot path.
 *
 * A conflict record names its conflicting fields as dot paths (`address.street`,
 * `items.0.name`); digits index a list. This reads the value at such a path and
 * writes one into a copy of the data, without a store.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sync
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sync;

use OCA\Dossiq\Service\Support\ReadsLooseValues;

/**
 * Reads and writes the value at a dot path.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DottedPath {
	use ReadsLooseValues {
		looseList as private arrayOf;
	}

	/**
	 * The value at a dot path, or null when the path does not exist.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $path The dot path.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function valueAt(array $data, string $path): mixed {
		$node = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($node) === false || array_key_exists($this->keyOf(segment: $segment), $node) === false) {
				return null;
			}

			$node = $node[$this->keyOf(segment: $segment)];
		}

		return $node;
	}//end valueAt()

	/**
	 * The data with the value set at a dot path.
	 *
	 * @param array<string, mixed> $data  The data.
	 * @param string               $path  The dot path.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed> The data with the value in place.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function withValueAt(array $data, string $path, mixed $value): array {
		$segments = explode('.', $path);
		$key = $this->keyOf(segment: array_shift($segments));
		if ($segments === []) {
			$data[$key] = $value;
			return $data;
		}

		$child = $this->arrayOf(value: ($data[$key] ?? null));
		$data[$key] = $this->withValueAt(data: $child, path: implode('.', $segments), value: $value);

		return $data;
	}//end withValueAt()

	/**
	 * A path segment as an array key: digits index a list.
	 *
	 * @param string $segment The segment.
	 *
	 * @return int|string The key.
	 */
	private function keyOf(string $segment): int|string {
		if (ctype_digit($segment) === true) {
			return (int)$segment;
		}

		return $segment;
	}//end keyOf()
}//end class
