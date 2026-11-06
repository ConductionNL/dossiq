<?php

/**
 * Dossiq package row target.
 *
 * Where a package writer puts a row: the object service, register and schema,
 * and what it does about a row the instance already holds.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Where a package row is written, and how a row already here is treated.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */
final class RowTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $schema        The schema, or the empty string before one is chosen.
	 * @param string $strategy      What to do about a row already here.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $strategy,
	) {
	}//end __construct()

	/**
	 * The same target in another schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
	 */
	public function inSchema(string $schema): self {
		return new self(objectService: $this->objectService, register: $this->register, schema: $schema, strategy: $this->strategy);
	}//end inSchema()

	/**
	 * The same target in another register and schema.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
	 */
	public function inRegister(string $register, string $schema): self {
		return new self(objectService: $this->objectService, register: $register, schema: $schema, strategy: $this->strategy);
	}//end inRegister()
}//end class
