<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Whether a row OpenRegister found lives in the schema it was asked under.
 *
 * OpenRegister's find falls back to every schema of the register when the
 * uuid is not in the one named, so a document's uuid answers a case lookup.
 * A row whose `@self.schema` names another schema is refused; one that does
 * not say, or says it as an id where the configuration holds a slug (or the
 * other way round), is kept.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
trait KeepsLookupsInSchema {
	/**
	 * Whether the row lives in the schema.
	 *
	 * @param array<string, mixed> $row The row as OpenRegister serialised it.
	 * @param string $schema The configured schema, an id or a slug.
	 *
	 * @return bool False when the row names another schema.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	private function rowInSchema(array $row, string $schema): bool {
		$found = ((array)($row['@self'] ?? []))['schema'] ?? null;
		if (is_scalar($found) === false || trim((string)$found) === '') {
			return true;
		}

		$found = strtolower(basename(trim((string)$found)));
		$asked = strtolower(basename(trim($schema)));
		if ($found === $asked) {
			return true;
		}

		return ctype_digit($found) !== ctype_digit($asked);
	}//end rowInSchema()
}//end trait
