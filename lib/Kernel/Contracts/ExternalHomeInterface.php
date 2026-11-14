<?php

/**
 * Contract of ExternalHome
 *
 * The public surface of ExternalHome that other components call, so they depend
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
 * Contract of ExternalHome.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExternalHomeInterface {

	/**
	 * The sentence a disabled act carries, naming where the work is done.
	 *
	 * It names the application and, when the case carries one, the identifier
	 * there, because a handler about to phone the specialist team needs the
	 * number they will be asked for.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string The sentence, '' when the case is handled here.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function whereTheWorkIs(array $case): string;
}//end interface
