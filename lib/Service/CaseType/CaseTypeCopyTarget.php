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
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * The object service, register and schema one step of a copy works on.
 *
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
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
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
	 */
	public function forSchema(string $schema): self {
		return new self(objectService: $this->objectService, register: $this->register, schema: $schema);
	}//end forSchema()

	/**
	 * Save an object into this register and schema, as the object service answers it.
	 *
	 * A refusal of the object service is not caught here: each step of the
	 * copy decides what a failed write means for it.
	 *
	 * @param array<string, mixed> $payload The object to save.
	 *
	 * @return mixed What the object service answers.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(array $payload): mixed {
		return $this->objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register, schema: $this->schema))
		);
	}//end save()

	/**
	 * Find an object by id in this register and schema, as the object service answers it.
	 *
	 * A refusal of the object service is not caught here, as for {@see save()}.
	 *
	 * @param string $id The object id.
	 *
	 * @return mixed What the object service answers, null when it found nothing.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function find(string $id): mixed {
		return $this->objectService->find($id, new ObjectFindOptions(register: $this->register, schema: $this->schema));
	}//end find()
}//end class
