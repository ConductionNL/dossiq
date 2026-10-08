<?php

/**
 * Dossiq case definition package ids.
 *
 * Every identifier a package carries, wherever in its json it sits. A conflict
 * is this instance already holding one of them, which is a different question
 * from the package carrying one at all, and the difference is what makes an
 * import say "already here" rather than mint a second row nothing points at.
 *
 * Split from {@see PackageValidator}: reading ids out of arbitrary json is a
 * recursive walk with no opinion about what makes a package valid.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use ZipArchive;

/**
 * Every identifier a case definition package carries.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class PackageIds {

	/**
	 * Every object id the package's component files carry.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<mixed> $components The components declared in the manifest.
	 *
	 * @return array<int, string> The ids.
	 */
	public function idsCarriedBy(\ZipArchive $zip, array $components): array {
		$ids = [];

		foreach ($components as $component) {
			$content = $zip->getFromName((string)$component . '.json');
			if ($content === false) {
				continue;
			}

			$data = json_decode((string)$content, true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collectIds(value: $data, ids: $ids);
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false || str_starts_with($name, 'workflows/') === false) {
				continue;
			}

			$data = json_decode((string)$zip->getFromIndex($i), true);
			if (is_array($data) === true) {
				$this->collectIds(value: $data, ids: $ids);
			}
		}

		return array_values(array_unique($ids));
	}//end idsCarriedBy()

	/**
	 * Collect every `id` and `@self.uuid` a decoded structure carries.
	 *
	 * @param mixed $value The decoded structure.
	 * @param array<int, string> $ids Collected ids, appended in place.
	 *
	 * @return void
	 */
	private function collectIds(mixed $value, array &$ids): void {
		if (is_array($value) === false) {
			return;
		}

		foreach (['id', 'uuid'] as $key) {
			$candidate = ($value[$key] ?? null);
			if (is_string($candidate) === true && trim($candidate) !== '') {
				$ids[] = trim($candidate);
			}
		}

		foreach ($value as $entry) {
			if (is_array($entry) === true) {
				$this->collectIds(value: $entry, ids: $ids);
			}
		}
	}//end collectIds()
}//end class
