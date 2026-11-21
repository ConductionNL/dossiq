<?php

/**
 * The answer one declared import gives, and how each record's outcome counts on it.
 *
 * Split out of {@see \OCA\Dossiq\Service\Import\CaseRecordImport}, which keeps
 * the reads, the writes and the decisions. This class reads and writes nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

/**
 * Opens an import's answer and counts outcomes on it.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RecordImportAnswer {

	/**
	 * An import's answer before anything was read, after its key and source app.
	 */
	public const NOTHING_YET = [
		'installed' => false,
		'imported' => 0,
		'alreadyImported' => 0,
		'failed' => [],
		'unmigrated' => 0,
		'migrated' => [],
		'duplicates' => [],
		'repointed' => 0,
	];

	/**
	 * Per outcome kind: the counter it raises and the list it joins, if any.
	 * Any other kind is a migration.
	 */
	private const COUNTS = [
		'alreadyImported' => ['alreadyImported', null],
		'unmigrated' => ['unmigrated', null],
		'failed' => ['unmigrated', 'failed'],
		'duplicate' => [null, 'duplicates'],
		'migrated' => ['imported', 'migrated'],
	];

	/**
	 * The answer of one declared import before any record is read.
	 *
	 * @param array<string, mixed> $import The declared import.
	 *
	 * @return array<string, mixed> Its key and source app, and nothing counted yet.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function opened(array $import): array {
		return [
			'key' => (string)($import['key'] ?? ''),
			'sourceApp' => (string)($import['sourceApp'] ?? ''),
		] + self::NOTHING_YET;
	}//end opened()

	/**
	 * The answer with one record's outcome counted.
	 *
	 * @param array<string, mixed>                          $answer  The answer so far.
	 * @param array{kind: string, row: array<string, mixed>} $outcome The record's outcome.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function counted(array $answer, array $outcome): array {
		$row = $outcome['row'];
		$answer['repointed'] += (int)($row['repointed'] ?? 0);
		unset($row['repointed']);

		[$counter, $list] = (self::COUNTS[$outcome['kind']] ?? self::COUNTS['migrated']);
		if ($counter !== null) {
			$answer[$counter]++;
		}

		if ($list !== null) {
			$answer[$list][] = $row;
		}

		return $answer;
	}//end counted()
}//end class
