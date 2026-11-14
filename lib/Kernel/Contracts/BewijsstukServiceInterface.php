<?php

/**
 * Contract of BewijsstukService
 *
 * The public surface of BewijsstukService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of BewijsstukService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BewijsstukServiceInterface {

	/**
	 * Guard against mutating/deleting a bewijsstuk linked to a vaststelling
	 * (REQ-SUB-007 immutability).
	 *
	 * @param array<string, mixed> $bewijsstuk The bewijsstuk record.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the document is immutable.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-007-bewijsstukken-management-with-bewaartermijn
	 */
	public function assertMutable(array $bewijsstuk): void;
}//end interface
