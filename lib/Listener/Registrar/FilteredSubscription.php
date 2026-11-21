<?php

/**
 * One object-lifecycle listener subscription with its schema interest.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\Listener\Registrar
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Registrar;

/**
 * Which listener reacts to which OpenRegister event, for which schemas.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class FilteredSubscription {
	/**
	 * Constructor.
	 *
	 * @param string            $event    OpenRegister event class name.
	 * @param string            $listener Listener class name.
	 * @param array<int,string> $schemas  Schema slugs the listener reacts to.
	 */
	public function __construct(
		public readonly string $event,
		public readonly string $listener,
		public readonly array $schemas,
	) {
	}//end __construct()
}//end class
