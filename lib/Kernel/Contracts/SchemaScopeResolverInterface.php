<?php

/**
 * Contract of SchemaScopeResolver
 *
 * The public surface of SchemaScopeResolver that other components call, so they depend
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
 * Contract of SchemaScopeResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SchemaScopeResolverInterface {

	/**
	 * The payload belongs to the named schema.
	 *
	 * @var string
	 */
	public const IN_SCOPE = 'in-scope';

	/**
	 * The payload belongs to a different schema.
	 *
	 * @var string
	 */
	public const OUT_OF_SCOPE = 'out-of-scope';

	/**
	 * Classify an object payload against one of Dossiq's schemas.
	 *
	 * @param array<string, mixed> $payload The object payload, including `@self`.
	 * @param string $configKey The appconfig key that holds the schema id.
	 * @param string $slug The schema slug, e.g. `milestoneDefinition`.
	 *
	 * @return string One of IN_SCOPE, OUT_OF_SCOPE or UNDECIDED.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function classify(array $payload, string $configKey, string $slug): string;
}//end interface
