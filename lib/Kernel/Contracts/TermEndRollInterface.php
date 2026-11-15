<?php

/**
 * Contract of TermEndRoll
 *
 * The public surface of TermEndRoll that other components call, so they depend
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
 * Contract of TermEndRoll.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermEndRollInterface {

	/**
	 * Whether an organisation calendar is answering at all.
	 *
	 * ASKED SO A SURFACE CAN SAY WHICH IT IS. A roll that was not needed and a
	 * roll that could not be made produce the same plausible date, so a page
	 * that shows the date and nothing else cannot tell an administrator that
	 * the Awt rule is currently inert on this instance.
	 *
	 * Deliberately not inferred from a roll's result: the roll falls back
	 * silently by design, because a term must still get a date.
	 *
	 * @return boolean True when both engine classes resolve.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function calendarAnswers(): bool;
}//end interface
