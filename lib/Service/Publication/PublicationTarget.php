<?php

/**
 * The register and schema a publication object is read from or written to.
 *
 * Built by each public method of `OpenCatalogiApiClient` and handed through its private read and write
 * steps.
 *
 * @category Publication
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * The register and schema a publication object is read from or written to.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
final class PublicationTarget {

	/**
	 * Constructor.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()

	/**
	 * Where OpenRegister finds an object of this register and schema.
	 *
	 * @return ObjectFindOptions
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function findOptions(): ObjectFindOptions {
		return new ObjectFindOptions(register: $this->register, schema: $this->schema);
	}//end findOptions()

	/**
	 * Where OpenRegister saves an object of this register and schema.
	 *
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectSaveOptions
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function saveOptions(?string $uuid): ObjectSaveOptions {
		return new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register, schema: $this->schema), uuid: $uuid);
	}//end saveOptions()
}//end class
