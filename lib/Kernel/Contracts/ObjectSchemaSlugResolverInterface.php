<?php

/**
 * Contract of ObjectSchemaSlugResolver
 *
 * The public surface of ObjectSchemaSlugResolver that other components call, so they depend
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
 * Contract of ObjectSchemaSlugResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectSchemaSlugResolverInterface {

	/**
	 * Resolve the schema slug for a serialised OpenRegister object.
	 *
	 * @param array<string, mixed> $payload The object payload, as produced by
	 *                                      `ObjectEntity::jsonSerialize()`.
	 *
	 * @return string The schema slug, or an empty string when it cannot be
	 *                resolved. An empty string never matches a slug literal,
	 *                so an unresolvable schema keeps the previous fail-closed
	 *                behaviour rather than invoking a handler blindly.
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function resolveFromPayload(array $payload): string;
}//end interface
