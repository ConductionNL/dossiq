<?php

/**
 * Contract of ClassificationSchemes
 *
 * The public surface of ClassificationSchemes that other components call, so they depend
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
 * Contract of ClassificationSchemes.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ClassificationSchemesInterface {

	/**
	 * Whether this instance can resolve a named scheme.
	 *
	 * An unnamed scheme resolves. A case type that names no scheme has not
	 * asked for one, which is a different fact from naming one nobody knows.
	 *
	 * @param string $scheme The scheme the case type names.
	 *
	 * @return boolean True when the scheme is known or unnamed.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function resolves(string $scheme): bool;

	/**
	 * The values one scheme allows.
	 *
	 * @param string $scheme The scheme.
	 *
	 * @return array<int, string> The allowed values, empty when unknown.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function valuesOf(string $scheme): array;
}//end interface
