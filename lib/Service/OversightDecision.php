<?php

/**
 * What a person did with one redaction proposal, as reported to AI oversight
 *
 * The user action (accepted, modified, rejected or ignored) and, for an
 * ignored proposal, why it went unused.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * One decision on a proposal; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
 */
final class OversightDecision {
	/**
	 * Constructor.
	 *
	 * @param string $userAction accepted, modified, rejected or ignored.
	 * @param string $because    Why an ignored proposal went unused.
	 */
	public function __construct(
		public readonly string $userAction,
		public readonly string $because = '',
	) {
	}//end __construct()
}//end class
