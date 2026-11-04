<?php

/**
 * Dossiq Case Type Copy Target
 *
 * Where a copy reads and writes: the object service, the register and one schema.
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
 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The object service, register and schema one step of a copy works on.
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
 */
final class CaseTypeCopyTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The register slug.
	 * @param string $schema        The schema id.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()

	/**
	 * The same object service and register, on another schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
	 */
	public function forSchema(string $schema): self {
		return new self(objectService: $this->objectService, register: $this->register, schema: $schema);
	}//end forSchema()
}//end class
