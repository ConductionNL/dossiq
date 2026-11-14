<?php

/**
 * One inbound call being routed to a specialist
 *
 * The skill the call needs, the specialists who have it and the overflow
 * limits: the state BelplanRoutingService::routeCall() builds once and its
 * steps read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * The state of one call routing; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */
final class CallRouting {

	/**
	 * The skill the call needs.
	 *
	 * @var string
	 */
	public string $vaardigheid = '';

	/**
	 * The specialists who have that skill.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $candidates = [];

	/**
	 * The estimated wait (seconds) above which the call overflows.
	 *
	 * @var integer
	 */
	public int $overflowWachttijd = 0;

	/**
	 * The shortest queue length above which the call overflows.
	 *
	 * @var integer
	 */
	public int $maxQueueLengte = 0;
}//end class
