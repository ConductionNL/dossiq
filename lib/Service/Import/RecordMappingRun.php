<?php

/**
 * One source record on its way to a case payload.
 *
 * Built by `RecordCaseMapping::map()` and never handed out: each declared
 * field is read off the record and written onto the case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

/**
 * The state of one record mapping.
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 */
final class RecordMappingRun {
	/**
	 * The case payload so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $record   The source record.
	 * @param string               $recordId Its uuid.
	 */
	public function __construct(
		public readonly array $record,
		public readonly string $recordId,
	) {
	}//end __construct()
}//end class
