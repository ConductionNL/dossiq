<?php

/**
 * What one work queue computation reads from and scores against.
 *
 * Where the cases are read from, the instant the scores are computed for and
 * the profile they are weighed with. One value carries them, so the steps of
 * a computation pass one argument instead of five.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;

/**
 * The source and the scoring numbers of one queue computation.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
final class QueueScoringRun {

	/**
	 * Constructor.
	 *
	 * @param CaseQueueSource   $source  Where the cases are read from.
	 * @param DateTimeImmutable $now     The instant the scores are computed against.
	 * @param UrgencyProfile    $profile The thresholds and weights.
	 */
	public function __construct(
		public readonly CaseQueueSource $source,
		public readonly DateTimeImmutable $now,
		public readonly UrgencyProfile $profile,
	) {
	}//end __construct()

	/**
	 * The same run, weighed with another profile.
	 *
	 * @param UrgencyProfile $profile The profile to score with.
	 *
	 * @return self The run with that profile.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function withProfile(UrgencyProfile $profile): self {
		return new self(source: $this->source, now: $this->now, profile: $profile);
	}//end withProfile()
}//end class
