<?php

/**
 * Dossiq ZGW PATCH merger.
 *
 * A ZGW PATCH carries only the fields it changes. OpenRegister saves whole objects, so the
 * patched fields are laid over the stored object before the save. Which mapped fields count as
 * patched, and which stored arrays go back to arrays after the Twig round-trip, is the whole
 * subtlety, and it lived inline in ZgwService::handleUpdate() (method-decomposition).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Lays the fields a ZGW PATCH body touched over the stored object.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwPatchMerger {

	/**
	 * Merge the patched, mapped fields over the stored object.
	 *
	 * Only English fields whose reverse-mapping template reads a ZGW field present in the body
	 * are taken from the mapped data. Stored arrays travel as JSON strings during the merge and
	 * come back as arrays afterwards, except fields the schema stores as JSON strings.
	 *
	 * @param array $existingData The stored object, serialised
	 * @param array $body The ZGW PATCH body (Dutch field names)
	 * @param array $englishData The body mapped to English field names
	 * @param array $mappingConfig The ZGW mapping (`reverseMapping`, `reverseCast`)
	 *
	 * @return array The object to save
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function merge(array $existingData, array $body, array $englishData, array $mappingConfig): array {
		$stored = $this->storedForTwig(existingData: $existingData);

		$reverseMap = $mappingConfig['reverseMapping'] ?? [];
		$patchData  = [];
		foreach ($this->patchedKeys(reverseMap: $reverseMap, bodyKeys: array_keys($body)) as $key) {
			if (isset($englishData[$key]) === true) {
				$patchData[$key] = $englishData[$key];
			}
		}

		$merged = array_merge($stored['data'], $patchData);

		// Restore fields that were originally arrays, but skip fields that are stored as JSON
		// strings in the schema (referenceProcess, relatedCaseTypes, ...): those must remain
		// JSON-encoded strings for OpenRegister validation.
		$jsonStringFields = $this->jsonStringFields(reverseMap: $reverseMap, reverseCast: $mappingConfig['reverseCast'] ?? []);
		foreach (array_diff($stored['arrayKeys'], $jsonStringFields) as $key) {
			$merged[$key] = $this->decodedArray(value: $merged[$key] ?? null);
		}

		return $merged;
	}//end merge()

	/**
	 * The stored object as it travels through the merge: identity fields dropped, an integer
	 * identifier as a string, and arrays JSON-encoded (as Twig would see them).
	 *
	 * @param array $existingData The stored object, serialised
	 *
	 * @return array{data: array, arrayKeys: array<int|string>} The prepared object and the keys that were arrays
	 */
	private function storedForTwig(array $existingData): array {
		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);

		if (isset($existingData['identifier']) === true && is_int($existingData['identifier']) === true) {
			$existingData['identifier'] = (string)$existingData['identifier'];
		}

		$arrayKeys = [];
		foreach ($existingData as $key => $value) {
			if (is_array($value) === true) {
				$arrayKeys[] = $key;
				$existingData[$key] = json_encode($value);
			}
		}

		return ['data' => $existingData, 'arrayKeys' => $arrayKeys];
	}//end storedForTwig()

	/**
	 * A JSON string that decodes to an array becomes that array; anything else stays.
	 *
	 * @param mixed $value The merged value
	 *
	 * @return mixed The array, or the value unchanged
	 */
	private function decodedArray(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $value;
	}//end decodedArray()

	/**
	 * The English fields whose reverse-mapping template reads a ZGW field the body carries.
	 *
	 * A template that reads more than one ZGW field is skipped, as it always was.
	 *
	 * @param array<string, string> $reverseMap English field => Twig template
	 * @param array<int|string> $bodyKeys The ZGW fields in the PATCH body
	 *
	 * @return array<string> The patched English fields (a field may repeat)
	 */
	private function patchedKeys(array $reverseMap, array $bodyKeys): array {
		$validKeys = [];
		foreach ($reverseMap as $engKey => $twigTpl) {
			if (preg_match_all('/\{\{\s*(\w+)/', $twigTpl, $matches) !== 1) {
				continue;
			}

			if (in_array($matches[1][0], $bodyKeys, true) === true) {
				$validKeys[] = $engKey;
			}
		}

		return $validKeys;
	}//end patchedKeys()

	/**
	 * The English fields the schema STORES as JSON strings.
	 *
	 * An encoding template is not enough to tell: Twig cannot emit an array, so a field backed
	 * by an ARRAY property is json_encode'd for transport and cast straight back by
	 * `reverseCast`. Reading the template alone put caseType.productsOrServices in this list
	 * and PATCH then wrote a string into an array property.
	 *
	 * @param array<string, string> $reverseMap English field => Twig template
	 * @param array<string, string> $reverseCast English field => cast
	 *
	 * @return array<string> The fields stored as JSON strings
	 */
	private function jsonStringFields(array $reverseMap, array $reverseCast): array {
		$fields = [];
		foreach ($reverseMap as $engKey => $twigTpl) {
			if (strpos($twigTpl, 'json_encode') !== false && ($reverseCast[$engKey] ?? '') !== 'jsonToArray') {
				$fields[] = $engKey;
			}
		}

		return $fields;
	}//end jsonStringFields()
}//end class
