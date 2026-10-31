<?php

/**
 * Dossiq case requester listener registrar.
 *
 * One listener, on both pre-persist events, so a case's requester projection
 * is written in the same save as the `requester` uuid it comes from.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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
 * @spec openspec/specs/initiator-display/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseRequesterProjectionListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case requester projection listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
class CaseRequesterListenerRegistrar {
	/**
	 * Where the projection runs relative to the rest: it reads only the
	 * requester and writes only the three projected fields, which no other
	 * pre-persist listener reads or writes, so the default order is enough.
	 */
	public const PROJECTION_PRIORITY = 0;

	/**
	 * Register the case requester projection listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/initiator-display/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(
				event: $event,
				listener: CaseRequesterProjectionListener::class,
				priority: self::PROJECTION_PRIORITY
			);
		}
	}//end register()
}//end class
