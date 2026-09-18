<?php

/**
 * Dossiq cross-app listener registrar.
 *
 * The two registrations that reach into another app's event classes: the flow
 * nodes dossiq contributes to OpenRegister, and the delivery seam integriq
 * concludes. Both are guarded on the event class existing, because both apps
 * are optional at runtime.
 *
 * They live here rather than in {@see ListenerRegistrar} because a class that
 * names another app's event AND its own listener couples to three more objects
 * than a registrar that only delegates, and ListenerRegistrar had reached the
 * coupling ceiling.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners that depend on another app being installed.
 *
 * @psalm-suppress UnusedClass
 */
class CrossAppListenerRegistrar {
	/**
	 * Register the cross-app event listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public function register(IRegistrationContext $context): void {
		// ADR-065: OpenRegister owns the flow engine; dossiq contributes the six
		// things a case can DO, because every one of OpenRegister's own nineteen
		// nodes is control-flow or data and none of them acts outward.
		//
		// Guarded on the event class, the same way hermiq guards its node
		// listener: `::class` is a compile-time string and does not autoload, so
		// an instance without OpenRegister still boots — it simply offers no
		// nodes rather than failing at registration.
		if (class_exists(\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class) === true) {
			$context->registerEventListener(
				\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
				\OCA\Dossiq\Flow\DossiqFlowNodeListener::class
			);
		}

		// ADR-041 delivery seam: integriq concludes a besluit-publication
		// delivery this app requested (PublicationService) with a terminal
		// DeliveryConcludedEvent; the listener projects the outcome onto the
		// case's publication record. FQN string, not ::class — integriq is an
		// optional runtime dependency and a cross-app event class name is a
		// runtime lookup this app can only follow (see the decidesk→decidiq
		// rename incident in WorkflowListenerRegistrar).
		if (class_exists('\\OCA\\Integriq\\Event\\DeliveryConcludedEvent') === true) {
			$context->registerEventListener(
				'OCA\Integriq\Event\DeliveryConcludedEvent',
				\OCA\Dossiq\Listener\DeliveryConcludedListener::class
			);
		}

		// leaf-integrations REQ-LEAF-103: a submission of a form a case type
		// bound opens a case with the statutory clock already running. `forms`
		// is optional, and the guard is what keeps an instance without it
		// booting: the event name is an FQN STRING on the listener, never an
		// import, because a type hint on a class the instance does not have is
		// a fatal when the container builds the listener rather than a feature
		// that is quietly missing.
		//
		// It also fails towards doing nothing. A wrong event name makes
		// `class_exists` answer false, nothing registers, and no submission
		// opens a case. For a path whose only act is CREATING work, that is
		// the right direction to fail in.
		if (class_exists(\OCA\Dossiq\Listener\FormSubmittedListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\FormSubmittedListener::EVENT,
				\OCA\Dossiq\Listener\FormSubmittedListener::class
			);
		}
	}//end register()
}//end class
