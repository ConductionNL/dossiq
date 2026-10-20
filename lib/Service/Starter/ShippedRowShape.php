<?php

/**
 * A shipped row as it is written rather than as it reads back.
 *
 * OpenRegister reads a property the register declares as a JSON-encoded string
 * back DECODED. Comparing such a row with a shipped one, or saving it again,
 * needs those properties encoded again, as JsonEncodedStringProperties lists
 * them for the schema the row's app config key names.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;

/**
 * Restores the written shape of a row that read back from the store.
 *
 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
 */
final class ShippedRowShape {

	/**
	 * The row with its JSON-encoded string properties encoded again.
	 *
	 * @param array<string, mixed> $object     The row as it reads back.
	 * @param string               $objectsKey The app config key naming its schema.
	 *
	 * @return array<string, mixed> The row as it is written.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function asWritten(array $object, string $objectsKey): array {
		$encoder = new JsonEncodedStringProperties();
		foreach (array_keys(SchemaSlugMap::SLUG_TO_CONFIG_KEY, $objectsKey, true) as $slug) {
			$object = $encoder->reencode(object: $object, schemaSlug: (string)$slug);
		}

		return $object;
	}//end asWritten()
}//end class
