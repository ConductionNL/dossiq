<?php

/**
 * Dossiq DecodesPackageFiles trait.
 *
 * Decodes one file of a case-definition package to a JSON object, recording
 * why when it cannot be. The using class provides an `$errors` list.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * One package file as a JSON object, or null with the reason recorded.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
trait DecodesPackageFiles {
	/**
	 * Decode one file to an object, recording why when it cannot be.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $path  The file.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function decode(array $files, string $path): ?array {
		if (isset($files[$path]) === false) {
			$this->errors[] = "{$path} is missing.";
			return null;
		}

		$data = json_decode($files[$path], true);
		if (is_array($data) === false || ($data !== [] && array_is_list($data) === true)) {
			$this->errors[] = "{$path} is not a JSON object: " . json_last_error_msg();
			return null;
		}

		return $data;
	}//end decode()
}//end trait
