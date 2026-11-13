<?php

/**
 * Contract of JsonEncodedStringProperties
 *
 * The public surface of JsonEncodedStringProperties that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of JsonEncodedStringProperties.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JsonEncodedStringPropertiesInterface {

	/**
	 * Merge an update onto a loaded object, keeping the declared string shape.
	 *
	 * The replacement for the bare `array_merge($loaded, $changes)` at every
	 * site that reads an object and writes it back. The update wins over the
	 * loaded value exactly as `array_merge` does; only the properties this
	 * class knows to be JSON-encoded strings are re-encoded, and only where
	 * the value actually arrived decoded.
	 *
	 * @param array<string, mixed> $stored     The object as OpenRegister returned it.
	 * @param array<string, mixed> $updates    The fields to change.
	 * @param string               $schemaSlug The schema's slug in dossiq's register.
	 *
	 * @return array<string, mixed> The payload to hand to `saveObject()`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function mergeForWrite(array $stored, array $updates, string $schemaSlug): array;
}//end interface
