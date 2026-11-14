<?php

/**
 * One term-event entry as it is written on the case timeline.
 *
 * Built by `TermEventEntry::recordEvent()` and `recordStart()` and handed to the step that writes it:
 * the case, the sentence a handler reads, the term instance, the event type, its legal basis and when
 * it happened.
 *
 * @category Timeline
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

/**
 * One term-event entry as it is written on the case timeline.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
final class TermEventLine {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId     The case the term hangs on.
	 * @param string               $message    The sentence a handler reads.
	 * @param array<string, mixed> $instance   The term instance, as stored.
	 * @param string               $event      The event type.
	 * @param string               $basis      The legal basis, '' when there is none.
	 * @param string               $occurredAt When it happened.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $message,
		public readonly array $instance,
		public readonly string $event,
		public readonly string $basis,
		public readonly string $occurredAt,
	) {
	}//end __construct()
}//end class
