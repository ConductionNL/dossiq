<?php

/**
 * Dossiq case link listener registrar.
 *
 * Registers the listener that applies the configured link marker rules after
 * an object is created or updated. One registrar per capability (ADR-118): the
 * rules are configuration, so no procedure gets a registrar of its own.
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\LinkMarkerListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the link marker listener.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
 */
class CaseLinkListenerRegistrar {
	/**
	 * Register the listener on both post-persist events.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: LinkMarkerListener::class);
		}
	}//end register()
}//end class
