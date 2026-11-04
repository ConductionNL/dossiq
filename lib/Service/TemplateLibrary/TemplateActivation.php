<?php

/**
 * One template activation: where its objects are saved, the case type they
 * hang off, and the status ids it has created so far.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\TemplateLibrary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\TemplateLibrary;

/**
 * The object store, register and case type of one activation.
 *
 * @spec openspec/specs/template-library/spec.md
 */
final class TemplateActivation {

	/**
	 * Constructor.
	 *
	 * @param object                $objectService The OpenRegister object service.
	 * @param string                $register      The Dossiq register slug.
	 * @param string                $caseTypeId    UUID of the caseType just created.
	 * @param array<string, string> $statusIds     Status name to uuid, from this activation.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseTypeId,
		public readonly array $statusIds = [],
	) {
	}//end __construct()

	/**
	 * The same activation, knowing the statuses it created.
	 *
	 * @param array<string, string> $statusIds Status name to uuid.
	 *
	 * @return self The activation.
	 *
	 * @spec openspec/specs/template-library/spec.md
	 */
	public function withStatusIds(array $statusIds): self {
		return new self(objectService: $this->objectService, register: $this->register, caseTypeId: $this->caseTypeId, statusIds: $statusIds);
	}//end withStatusIds()

	/**
	 * The uuid this activation gave the status of that name, or ''.
	 *
	 * @param mixed $name The status name as a transition writes it.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/specs/template-library/spec.md
	 */
	public function statusId(mixed $name): string {
		return ($this->statusIds[trim((string)$name)] ?? '');
	}//end statusId()
}//end class
