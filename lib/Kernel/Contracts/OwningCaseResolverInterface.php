<?php

/**
 * Contract of OwningCaseResolver
 *
 * The public surface of OwningCaseResolver that other components call, so they depend
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
 * Contract of OwningCaseResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OwningCaseResolverInterface {

	/**
	 * Resolve the owning case across two hops.
	 *
	 * Some child objects do not reference their case directly. A
	 * `dwangsomBerekening`, for example, carries only `termijnInstance`, and it
	 * is the `termijnInstance` that carries `zaak`. Guarding those endpoints
	 * needs the whole chain, and every unresolvable link must DENY rather than
	 * fall through — a missing intermediate is not permission.
	 *
	 * @param string $objectId The child object UUID.
	 * @param string $schemaKey The settings key naming the child's schema.
	 * @param string $linkField The property on the child holding the intermediate ref.
	 * @param string $viaSchemaKey The settings key naming the intermediate's schema.
	 * @param string $caseField The property on the intermediate holding the case ref.
	 *
	 * @return string|null The owning case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function resolveVia(
		string $objectId,
		string $schemaKey,
		string $linkField,
		string $viaSchemaKey,
		string $caseField = 'case',
	): ?string;
}//end interface
