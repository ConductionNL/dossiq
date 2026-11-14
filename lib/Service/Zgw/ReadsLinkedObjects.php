<?php

/**
 * Reads the object a ZGW URL points at
 *
 * Two reads every cross-register rule repeats: the object behind a URL, and a
 * list field that is stored as an array or as JSON.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Lookup by URL and list decoding for classes built on ZgwRulesBase.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
trait ReadsLinkedObjects {
	/**
	 * Look up the object a URL's uuid names in the given schema.
	 *
	 * @param string $url The object URL
	 * @param string $schemaKey Settings key of the object's schema
	 *
	 * @return array|null The object, or null when the URL holds no uuid or nothing is found
	 */
	private function getObjectByUrl(string $url, string $schemaKey): ?array {
		$uuid = $this->extractUuid(url: $url);
		if ($uuid === null) {
			return null;
		}

		return $this->findBySchemaKey(uuid: $uuid, schemaKey: $schemaKey);
	}//end getObjectByUrl()

	/**
	 * Read a list field that may arrive as an array or a JSON string.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return array The list, empty when the value is neither a list nor JSON for one
	 */
	private function decodeList(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true) ?? [];
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end decodeList()
}//end trait
