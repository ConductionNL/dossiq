<?php

/**
 * A portal contact channel change: whose it is, in which organisation and which channel.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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

namespace OCA\Dossiq\Listener;

/**
 * A portal contact channel change: whose it is, in which organisation and which channel.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ContactChannelChange {
	/**
	 * Constructor.
	 *
	 * @param string $subject The portal subject reference
	 * @param string $organisation The organisation the event names, or ''
	 * @param string $channel The portal channel
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $organisation,
		public readonly string $channel,
	) {
	}//end __construct()
}//end class
