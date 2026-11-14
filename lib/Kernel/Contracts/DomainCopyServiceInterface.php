<?php

/**
 * Contract of DomainCopyService
 *
 * The public surface of DomainCopyService that other components call, so they depend
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
 * Contract of DomainCopyService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DomainCopyServiceInterface {

	/**
	 * Copy one domain into a new one.
	 *
	 * @param string $domainId The domain to copy.
	 * @param string $name     What the new domain is called.
	 *
	 * @return array{complete: bool, domain: string, carried: array<string, int>, notCarried: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function copy(string $domainId, string $name): array;
}//end interface
