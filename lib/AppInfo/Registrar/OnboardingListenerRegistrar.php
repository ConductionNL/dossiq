<?php

/**
 * Dossiq onboarding listeners registrar.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\RolePermissionsInitialStateListener;
use OCA\Dossiq\Listener\SupportNoteInitialStateListener;
use OCA\Dossiq\Listener\WalkthroughSeenInitialStateListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

/**
 * Registers the listeners of a user's first loads.
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 */
class OnboardingListenerRegistrar {

	/**
	 * Register the onboarding listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-load-onboarding/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: WalkthroughSeenInitialStateListener::class
		);
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: SupportNoteInitialStateListener::class
		);
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: RolePermissionsInitialStateListener::class
		);
	}//end register()
}//end class
