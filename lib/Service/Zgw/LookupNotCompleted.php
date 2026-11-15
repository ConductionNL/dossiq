<?php

/**
 * A store read of a ZGW business rule that could not run
 *
 * A lookup that ran and found nothing is an answer. A lookup that could not
 * run is not, and this exception is how a lookup says so. The entry point of
 * the rules turns it into one refusal, so no rule decides on a read that
 * never happened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use RuntimeException;
use Throwable;

/**
 * Thrown by a rule lookup whose read failed, naming what it was looking up.
 *
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */
final class LookupNotCompleted extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $lookup What the lookup was reading, for the log (`reference name "Brief"`).
	 * @param Throwable $cause Why the read failed.
	 */
	public function __construct(
		public readonly string $lookup,
		Throwable $cause,
	) {
		parent::__construct(message: $cause->getMessage(), previous: $cause);
	}//end __construct()
}//end class
