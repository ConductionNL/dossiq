<?php

/**
 * Dossiq access exception value: reads the exception an informatieobject carries
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * Stateless, so a reader that runs without a user (the resident portal) can ask it too.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
 */
class AccessExceptionValue {

	/**
	 * The property on the informatieobject.
	 */
	public const PROPERTY = 'accessException';

	/**
	 * The property holding every set, change and removal.
	 */
	public const HISTORY = 'accessExceptionHistory';

	/**
	 * The exception a document carries, or null when it carries none.
	 *
	 * An exception that names nobody is treated as none: it would otherwise lock everyone but the handler out
	 * without a decision to do so.
	 *
	 * @param array<string, mixed> $record The informatieobject.
	 *
	 * @return array{users: list<string>, groups: list<string>, reason: string, setBy: string, setAt: string}|null The exception.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function exceptionOf(array $record): ?array {
		$raw = ($record[self::PROPERTY] ?? null);
		if (is_array($raw) === false) {
			return null;
		}

		$users = $this->cleanList(values: (array)($raw['users'] ?? []));
		$groups = $this->cleanList(values: (array)($raw['groups'] ?? []));
		if ($users === [] && $groups === []) {
			return null;
		}

		return [
			'users' => $users,
			'groups' => $groups,
			'reason' => (string)($raw['reason'] ?? ''),
			'setBy' => (string)($raw['setBy'] ?? ''),
			'setAt' => (string)($raw['setAt'] ?? ''),
		];
	}//end exceptionOf()

	/**
	 * A list of principal ids, trimmed, without empties and duplicates.
	 *
	 * @param array<mixed> $values The ids.
	 *
	 * @return list<string> The clean list.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function cleanList(array $values): array {
		$clean = [];
		foreach ($values as $value) {
			if (is_string($value) === false && is_int($value) === false) {
				continue;
			}

			$value = trim((string)$value);
			if ($value !== '' && in_array($value, $clean, true) === false) {
				$clean[] = $value;
			}
		}

		return $clean;
	}//end cleanList()
}//end class
