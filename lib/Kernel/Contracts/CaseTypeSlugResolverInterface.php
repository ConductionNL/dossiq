<?php

/**
 * Contract of CaseTypeSlugResolver
 *
 * The public surface of CaseTypeSlugResolver that other components call, so they depend
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
 * Contract of CaseTypeSlugResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeSlugResolverInterface {

	/**
	 * Resolve a case-type reference to the slug the register keys by.
	 *
	 * @param string $reference The caseType uuid a case carries, or a slug.
	 *
	 * @return string The slug, or an empty string when a uuid cannot be
	 *                resolved. An empty string matches no definition, so an
	 *                unresolvable case type keeps the fail-closed behaviour.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function toSlug(string $reference): string;
}//end interface
