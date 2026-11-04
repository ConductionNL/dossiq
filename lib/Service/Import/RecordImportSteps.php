<?php

/**
 * What a record import goes through: the mapping onto a case, the term carry-over and the import store.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use OCA\Dossiq\Service\Term\TermCarryOver;

/**
 * What a record import goes through: the mapping onto a case, the term carry-over and the import store.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */
final class RecordImportSteps {
	/**
	 * Constructor.
	 *
	 * @param RecordCaseMapping $mapping One record onto a case payload.
	 * @param TermCarryOver     $term    The term carried onto the written case.
	 * @param RecordImportStore $store   Reads the sources and cases, writes the case and the stamp.
	 */
	public function __construct(
		public readonly RecordCaseMapping $mapping,
		public readonly TermCarryOver $term,
		public readonly RecordImportStore $store,
	) {
	}//end __construct()
}//end class
