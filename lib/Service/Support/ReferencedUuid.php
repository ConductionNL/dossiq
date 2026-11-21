<?php

/**
 * The uuid a stored reference names
 *
 * References to cases, documents and links are stored in several shapes: a
 * bare uuid, or a row carrying it. A bare uuid is trimmed. How a row carries
 * it depends on who wrote it: an objection dossier row under the first of
 * `id`, `uuid`, `@self.uuid`, `case` or `target` that holds a string, any
 * other reference row under `id`. Anything else names nothing.
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
 * Reads the uuid out of a stored reference.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ReferencedUuid {
	/**
	 * The keys a dossier reference row may carry its uuid under, in the order they are tried.
	 *
	 * @var array<int, string>
	 */
	private const DOSSIER_KEYS = ['id', 'uuid', '@self.uuid', 'case', 'target'];

	/**
	 * The uuid of a dossier reference: a bare uuid, or the first dossier key that holds a string.
	 *
	 * @param mixed $value A bare uuid, a reference row, or anything else.
	 *
	 * @return string The trimmed uuid, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function inDossierReference(mixed $value): string {
		return self::read(value: $value, fromRow: self::underDossierKey(...));
	}//end inDossierReference()

	/**
	 * The uuid of a plain reference: a bare uuid, or a row's `id` as a string.
	 *
	 * @param mixed $value A bare uuid, a reference row, or anything else.
	 *
	 * @return string The trimmed uuid, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function inReference(mixed $value): string {
		return self::read(value: $value, fromRow: static fn (array $row): string => trim((string)($row['id'] ?? '')));
	}//end inReference()

	/**
	 * A bare uuid trimmed, a row read the given way, anything else empty.
	 *
	 * @param mixed    $value   The stored reference.
	 * @param callable $fromRow Reads the uuid out of a row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private static function read(mixed $value, callable $fromRow): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return $fromRow($value);
		}

		return '';
	}//end read()

	/**
	 * The first dossier key of a row that holds a string, trimmed.
	 *
	 * @param array<string, mixed> $row The reference row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private static function underDossierKey(array $row): string {
		foreach (self::DOSSIER_KEYS as $key) {
			if (isset($row[$key]) === true && is_string($row[$key]) === true) {
				return trim($row[$key]);
			}
		}

		return '';
	}//end underDossierKey()
}//end class
