<?php

/**
 * One configured schema a repair step walks and patches.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use Generator;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * OpenRegister's object service, the register and one schema: its rows page by page, and a partial write to one of them.
 *
 * Built by {@see RepairSchemas}; a repair step reads it and hands it to the
 * walk it runs under the system identity.
 *
 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
 *  own spec.
 */
final class RepairSchema {
	use SearchesObjects;

	/**
	 * OpenRegister's object service.
	 *
	 * @var object
	 */
	public readonly object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Start a walk over one register.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 */
	public function __construct(object $objectService, string $register) {
		$this->objectService = $objectService;
		$this->register = $register;
	}//end __construct()

	/**
	 * Every row of the schema, a page at a time: the next page is read once the last row of this one is handled.
	 *
	 * @param int $pageSize How many rows one page holds.
	 *
	 * @return Generator<int, array<string, mixed>, mixed, void> The rows.
	 *
	 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
	 *  own spec.
	 */
	public function rows(int $pageSize): Generator {
		$page = 1;
		$full = true;
		while ($full === true) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $this->objectService,
				register: $this->register,
				schema: $this->schema,
				filters: ['_limit' => $pageSize, '_page' => $page],
			);
			$full = (count($rows) === $pageSize);
			foreach ($rows as $row) {
				yield $row;
			}

			$page++;
		}
	}//end rows()

	/**
	 * Write some fields of one row.
	 *
	 * @param string               $id      The row.
	 * @param array<string, mixed> $changes The fields.
	 *
	 * @return void
	 *
	 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
	 *  own spec.
	 */
	public function patch(string $id, array $changes): void {
		$this->patchObjectAsArray(
			objectService: $this->objectService,
			register: $this->register,
			schema: $this->schema,
			id: $id,
			changes: $changes,
		);
	}//end patch()

	/**
	 * A row's id, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
	 *  own spec.
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
	}//end idOf()
}//end class
