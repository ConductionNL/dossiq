<?php

/**
 * Contract of CoveredWork
 *
 * The public surface of CoveredWork that other components call, so they depend
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

use DateTimeImmutable;
use OCP\IUser;

/**
 * Contract of CoveredWork.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CoveredWorkInterface {

	/**
	 * The open cases this user covers on a day, each with `coveredFor`.
	 *
	 * @param IUser                  $user The substitute.
	 * @param DateTimeImmutable|null $date The day; today when null.
	 *
	 * @return array<int, array<string, mixed>> The open cases, each with `coveredFor: {absentee, name, until}`.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function casesFor(IUser $user, ?DateTimeImmutable $date = null): array;
}//end interface
