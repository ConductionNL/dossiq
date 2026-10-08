<?php

/**
 * An InMemoryRegister whose saves can be made to fail.
 *
 * A bezwaar change that is written after its entry must leave a
 * `<event>-not-applied` entry when the write fails. A store that cannot fail
 * cannot prove that, so this one can.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use RuntimeException;

class RefusableRegister extends InMemoryRegister {
	/**
	 * Whether every save throws.
	 *
	 * @var bool
	 */
	public bool $refuseSaves = false;

	/**
	 * Save one row, unless saves are refused.
	 *
	 * @param array<string, mixed> $object        The row.
	 * @param int|string           $register      The register.
	 * @param int|string           $schema        The schema.
	 * @param string|null          $uuid          The uuid, or null for a new row.
	 * @param bool                 $_rbac         Unused.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return array<string, mixed> The saved row.
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		if ($this->refuseSaves === true) {
			throw new RuntimeException('object store refused the save');
		}

		return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end saveObject()
}//end class
