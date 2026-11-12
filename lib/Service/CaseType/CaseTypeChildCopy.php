<?php

/**
 * Dossiq Case Type Child Copy
 *
 * One owned sub-schema being copied onto a new case type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The children of one owned sub-schema, copied onto the new case type.
 *
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
 */
final class CaseTypeChildCopy {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeCopyTarget $target        Where the children are read and written.
	 * @param string             $configKey     The settings key of the child schema.
	 * @param string             $newCaseTypeId The new case type's id.
	 */
	public function __construct(
		public readonly CaseTypeCopyTarget $target,
		public readonly string $configKey,
		public readonly string $newCaseTypeId,
	) {
	}//end __construct()

	/**
	 * The same copy, reading and writing another schema.
	 *
	 * @param string $schema The child schema id.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
	 */
	public function forSchema(string $schema): self {
		return new self(target: $this->target->forSchema(schema: $schema), configKey: $this->configKey, newCaseTypeId: $this->newCaseTypeId);
	}//end forSchema()
}//end class
