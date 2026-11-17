<?php

/**
 * Dossiq template metadata.
 *
 * What the template library lists for one template file: its id, title,
 * description, category, version, file name, what it fills in and the notes
 * it carries for the generate dialog.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\TemplateLibrary
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

namespace OCA\Dossiq\Service\TemplateLibrary;

/**
 * One decoded template read as a library entry; reads no file.
 *
 * @spec openspec/specs/template-library/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class TemplateMetadata {

	/**
	 * The metadata the library lists for one template.
	 *
	 * @param array<string, mixed> $data The decoded template.
	 * @param string               $file The template file path.
	 *
	 * @return array<string, mixed> The template metadata.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function entry(array $data, string $file): array {
		return [
			'id' => $data['id'] ?? pathinfo($file, PATHINFO_FILENAME),
			'title' => $data['title'] ?? '',
			'description' => $data['description'] ?? '',
			'category' => $data['category'] ?? 'general',
			'version' => $data['version'] ?? '1.0.0',
			'file' => basename($file),
			'fills' => $this->fills(data: $data),
			'notes' => $this->notes(data: $data),
		];
	}//end entry()

	/**
	 * What a template fills in from the case, for the generate dialog.
	 *
	 * @param array<string, mixed> $data The template file's content.
	 *
	 * @return array<int, mixed> The template's fills, or an empty list.
	 *
	 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-a-template-names-what-it-fills-in
	 */
	private function fills(array $data): array {
		if (isset($data['fills']) === false || is_array($data['fills']) === false) {
			return [];
		}

		return array_values($data['fills']);
	}//end fills()

	/**
	 * The notes a template carries for whoever generates it, e.g. about the objection period.
	 *
	 * @param array<string, mixed> $data The template file's content.
	 *
	 * @return array<int, string> The notes, blanks and non-strings left out.
	 *
	 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-a-template-carries-notes-for-the-generate-dialog
	 */
	private function notes(array $data): array {
		if (isset($data['notes']) === false || is_array($data['notes']) === false) {
			return [];
		}

		$notes = [];
		foreach ($data['notes'] as $note) {
			if (is_string($note) === true && trim($note) !== '') {
				$notes[] = trim($note);
			}
		}

		return $notes;
	}//end notes()
}//end class
