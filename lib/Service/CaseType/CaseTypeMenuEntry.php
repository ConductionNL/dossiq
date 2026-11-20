<?php

/**
 * Dossiq case type menu entry.
 *
 * What the case type menu shows of one stored case type row: its uuid, its
 * title in the reader's language, its identifier, its icon and the teams that
 * handle it, and the public shape the menu is sent.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * One case type row read as a menu entry; reads no store.
 *
 * @spec openspec/specs/case-type-navigation/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseTypeMenuEntry {

	/**
	 * Constructor.
	 *
	 * @param TranslatedText $text Reads a title in the reader's language.
	 */
	public function __construct(
		private readonly TranslatedText $text,
	) {
	}//end __construct()

	/**
	 * A case type as the menu and its picker see it: id and title, and its
	 * icon and description when it names them.
	 *
	 * @param array{id: string, title: string, icon: string, description?: string} $row The case type.
	 *
	 * @return array{id: string, title: string, icon?: string, description?: string} The row.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
	 * @spec openspec/changes/saved-filters-manage-and-defaults
	 */
	public function publicRow(array $row): array {
		$public = ['id' => $row['id'], 'title' => $row['title']];
		if ($row['icon'] !== '') {
			$public['icon'] = $row['icon'];
		}

		if (($row['description'] ?? '') !== '') {
			$public['description'] = $row['description'];
		}

		return $public;
	}//end publicRow()

	/**
	 * One case type as the menu lists it.
	 *
	 * The title is declared translatable, so a row carries it as a language
	 * map. A (string) cast of that map is "Array", which is what every case
	 * type in the picker, the chosen list and the menu was called (finding
	 * B2, 10 Oct).
	 *
	 * @param array<string, mixed> $row  The case type row.
	 * @param string               $uuid Its uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md
	 */
	public function entry(array $row, string $uuid): array {
		$title = $this->text->forReader(value: ($row['title'] ?? ''));
		if ($title === '') {
			$title = $uuid;
		}

		return [
			'id' => $uuid,
			'title' => $title,
			'identifier' => (string)($row['identifier'] ?? ''),
			'icon' => $this->iconOf(row: $row),
			'description' => trim($this->text->forReader(value: ($row['description'] ?? ''))),
			'teams' => (new CaseTypeHandling())->teams(caseType: $row),
		];
	}//end entry()

	/**
	 * The icon a case type row names (`caseType.icon`), or '' when it names none.
	 *
	 * @param array<string, mixed> $row The case type row.
	 *
	 * @return string The icon name.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
	 */
	private function iconOf(array $row): string {
		$icon = ($row['icon'] ?? '');
		if (is_string($icon) === false) {
			return '';
		}

		return trim($icon);
	}//end iconOf()

	/**
	 * Resolve an OpenRegister object's UUID from its array shape.
	 *
	 * @param array<string, mixed> $object The object as an associative array.
	 *
	 * @return string|null The UUID, or null when none is resolvable.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function uuidOf(array $object): ?string {
		$self = ($object['@self'] ?? null);

		$candidates = [
			($object['uuid'] ?? null),
			($object['id'] ?? null),
		];

		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}

			if (is_int($candidate) === true) {
				return (string)$candidate;
			}
		}

		return null;
	}//end uuidOf()
}//end class
