<?php

/**
 * Contract of CaseRecordImport
 *
 * The public surface of CaseRecordImport that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseRecordImport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRecordImportInterface {

	/**
	 * Count what has not moved, writing nothing.
	 *
	 * @param string $caseType  The case type, by uuid or identifier.
	 * @param string $importKey One declared import's key, or '' for all.
	 *
	 * @return array{caseType: string, imports: list<array<string, mixed>>} As {@see run()}, with nothing imported.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function dryRun(string $caseType, string $importKey = ''): array;

	/**
	 * Run every import a case type declares, or the one named.
	 *
	 * @param string $caseType  The case type, by uuid or identifier.
	 * @param string $importKey One declared import's key, or '' for all.
	 *
	 * @return array{caseType: string, imports: list<array<string, mixed>>} One answer per import:
	 *         `{key, sourceApp, installed, imported, alreadyImported, failed, unmigrated, migrated}`.
	 *         An empty `caseType` means the case type was not found.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function run(string $caseType, string $importKey = ''): array;

	/**
	 * Run every declaration flagged `runOnUpgrade` of every case type version in use.
	 *
	 * @return list<array<string, mixed>> One answer per declaration, as {@see run()} gives them,
	 *                                    each with the `caseType` it belongs to.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	public function runOnUpgrade(): array;
}//end interface
