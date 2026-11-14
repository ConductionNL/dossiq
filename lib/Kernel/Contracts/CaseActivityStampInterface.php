<?php

/**
 * Contract of CaseActivityStamp
 *
 * The public surface of CaseActivityStamp that other components call, so they depend
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
 * Contract of CaseActivityStamp.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseActivityStampInterface {

	/**
	 * The case property the stamp sets.
	 *
	 * @var string
	 */
	public const FIELD = 'lastActivityAt';

	/**
	 * Mark the case as worked on now.
	 *
	 * An id that is not a case is no error: a note or a task can hang on any
	 * object, and only a case has an activity date.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case was stamped.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	public function stamp(string $caseId): bool;
}//end interface
