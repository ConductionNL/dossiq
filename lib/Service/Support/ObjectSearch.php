<?php

/**
 * One OpenRegister object search: where to look, what to match and in which scope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The inputs of one object search, as SearchesObjects hands them on.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
 */
final class ObjectSearch {

	/**
	 * Constructor.
	 *
	 * @param object               $objectService The OpenRegister ObjectService instance.
	 * @param int|string           $register      Register numeric ID or slug.
	 * @param int|string           $schema        Schema numeric ID or slug.
	 * @param array<string, mixed> $filters       Object-field filters plus pagination keys.
	 * @param array<string, bool>  $scope         The scope-lifting parameters, or none.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly int|string $register,
		public readonly int|string $schema,
		public readonly array $filters,
		public readonly array $scope,
	) {
	}//end __construct()

	/**
	 * Whether both register and schema are numeric identifiers.
	 *
	 * @return bool True when the numeric-ID search path applies.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function byNumericIds(): bool {
		$registerIsNumeric = (is_int($this->register) === true || ctype_digit((string)$this->register) === true);
		$schemaIsNumeric = (is_int($this->schema) === true || ctype_digit((string)$this->schema) === true);

		return $registerIsNumeric === true && $schemaIsNumeric === true;
	}//end byNumericIds()
}//end class
