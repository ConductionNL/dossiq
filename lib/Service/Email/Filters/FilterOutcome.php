<?php

/**
 * Dossiq Inbound Filter Outcomes
 *
 * The five answers a filter may give, and the default a pipeline that decided
 * nothing falls through to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email\Filters;

/**
 * What a filter can say about a message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class FilterOutcome {

	/**
	 * This message may become a case. Ends the pipeline.
	 */
	public const ACCEPT = 'accept';

	/**
	 * This message must not become a case. Ends the pipeline.
	 */
	public const REJECT = 'reject';

	/**
	 * A person decides. Ends the pipeline and nothing is deleted.
	 */
	public const QUARANTINE = 'quarantine';

	/**
	 * This message belongs to somebody else. Ends the pipeline and is sent on.
	 */
	public const FORWARD = 'forward';

	/**
	 * This filter has no opinion. The next filter runs.
	 */
	public const PASS = 'pass';

	/**
	 * What a message reaching the end of the pipeline becomes.
	 *
	 * Written down rather than implied (design D-3). A pipeline whose default
	 * is a silent drop loses statutory terms; a pipeline whose default is
	 * accept loses nothing, because every later stage still runs.
	 */
	public const DEFAULT_OUTCOME = self::ACCEPT;

	/**
	 * Every outcome.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::ACCEPT,
		self::REJECT,
		self::QUARANTINE,
		self::FORWARD,
		self::PASS,
	];

	/**
	 * Whether an outcome ends the pipeline.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return boolean True for everything except `pass`.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function isDecisive(string $outcome): bool {
		return ($outcome !== self::PASS && in_array($outcome, self::ALL, true) === true);
	}//end isDecisive()
}//end class
