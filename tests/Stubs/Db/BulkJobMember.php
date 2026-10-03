<?php

/**
 * Stub of OpenRegister's BulkJobMember, for its outcome constants only.
 *
 * `BulkActionResult` names its four outcomes through this class's constants,
 * so the stub carries the constants and nothing else. Mirrors openregister
 * `lib/Db/BulkJobMember.php`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One object's place in a bulk job.
 */
class BulkJobMember {

	/**
	 * The action wrote this object.
	 *
	 * @var string
	 */
	public const OUTCOME_APPLIED = 'applied';

	/**
	 * The action does not apply to this object.
	 *
	 * @var string
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * The actor may not write this object.
	 *
	 * @var string
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * The write threw.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';
}//end class
