<?php

/**
 * Dossiq own page render
 *
 * Whether a template render is dossiq's own page for a signed-in user, the
 * one page the initial-state listeners provide their state on.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/specs/first-load-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;

/**
 * Tells dossiq's own logged-in page render from every other event.
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 */
final class OwnPageRender {

	/**
	 * Whether the event renders dossiq's own page for a signed-in user.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/first-load-onboarding/spec.md
	 */
	public function matches(Event $event): bool {
		return $event instanceof BeforeTemplateRenderedEvent
			&& $event->isLoggedIn() === true
			&& $event->getResponse()->getApp() === AppIdentity::APP_ID;
	}//end matches()
}//end class
