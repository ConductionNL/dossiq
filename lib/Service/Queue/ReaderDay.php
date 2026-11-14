<?php

/**
 * The reader whose day is worked out, and that day in their own time zone.
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
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeZone;

/**
 * The reader whose day is worked out, and that day in their own time zone.
 *
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */
final class ReaderDay {

	/**
	 * Constructor.
	 *
	 * @param string       $userId The reader.
	 * @param string       $today  Today, `Y-m-d` in the reader's zone.
	 * @param DateTimeZone $zone   The reader's zone.
	 *
	 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $today,
		public readonly DateTimeZone $zone,
	) {
	}//end __construct()
}//end class
