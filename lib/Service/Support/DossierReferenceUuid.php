<?php

/**
 * The uuid a dossier reference names
 *
 * Objection dossiers and the dossiers compiled from them refer to cases,
 * documents and links in several shapes: a bare uuid, or a row carrying it
 * under `id`, `uuid`, `@self.uuid`, `case` or `target`. The first of those
 * that holds a string is the uuid; anything else names nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Reads the uuid out of a dossier reference.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class DossierReferenceUuid {
	/**
	 * The keys a reference row may carry its uuid under, in the order they are tried.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = ['id', 'uuid', '@self.uuid', 'case', 'target'];

	/**
	 * The uuid a reference names, trimmed, or an empty string.
	 *
	 * @param mixed $value A bare uuid, a reference row, or anything else.
	 *
	 * @return string The uuid, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function from(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return self::fromRow(row: $value);
		}

		return '';
	}//end from()

	/**
	 * The first of the reference keys that holds a string, trimmed.
	 *
	 * @param array<string, mixed> $row The reference row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private static function fromRow(array $row): string {
		foreach (self::KEYS as $key) {
			if (isset($row[$key]) === true && is_string($row[$key]) === true) {
				return trim($row[$key]);
			}
		}

		return '';
	}//end fromRow()
}//end class
