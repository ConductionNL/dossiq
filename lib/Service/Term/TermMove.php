<?php

/**
 * Dossiq TermMove
 *
 * The term that moved and what a dependent's offer says about it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

/**
 * One moved term: the case it belongs to, what that case is called and how far it moved.
 *
 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
 */
final class TermMove {
	/**
	 * Constructor.
	 *
	 * @param string $sourceCaseId The case whose term moved.
	 * @param string $sourceTitle  What that case is called, possibly empty.
	 * @param int    $daysImpact   How many days it moved by.
	 */
	public function __construct(
		public readonly string $sourceCaseId,
		public readonly string $sourceTitle,
		public readonly int $daysImpact,
	) {
	}//end __construct()

	/**
	 * How the task text names the moved case: its title, or its id when it has none.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function named(): string {
		if ($this->sourceTitle !== '') {
			return $this->sourceTitle;
		}

		return $this->sourceCaseId;
	}//end named()
}//end class
