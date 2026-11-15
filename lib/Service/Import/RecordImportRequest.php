<?php

/**
 * Dossiq RecordImportRequest.
 *
 * What an import run was asked for: the case type, which of its declared imports, and whether to only count.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

/**
 * The case type, the import key (or '' for all) and whether the run only counts.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */
final class RecordImportRequest {

	/**
	 * Constructor.
	 *
	 * @param string $caseType  The case type, by uuid or identifier.
	 * @param string $importKey One declared import's key, or '' for all.
	 * @param bool   $dryRun    Count only.
	 */
	public function __construct(
		public readonly string $caseType,
		public readonly string $importKey,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
