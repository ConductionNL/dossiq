<?php

/**
 * Dossiq cross-app listener registrar.
 *
 * The registrations that reach into another app's event classes: the flow
 * nodes dossiq contributes to OpenRegister, the seams integriq concludes, and
 * the citizen facts portaliq raises. Each is guarded on the event class
 * existing, because every one of those apps is optional at runtime.
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
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerFlowNodes(context: $context);
		$this->registerOwnedStepListeners(context: $context);
		$this->registerDeliveryListeners(context: $context);
		$this->registerIntakeListeners(context: $context);
		$this->registerPortalListeners(context: $context);
	}//end register()

	/**
	 * Take back what the apps that own mail and documents did for a case.
	 *
	 * Dossiq's mail and document steps are OpenRegister's and Filinq's now.
	 * Each announces what it did, and dossiq files it on the case: the sent
	 * mail on the case and its timeline, the generated document in the
	 * dossier. Guarded on the event class and named by string, like every
	 * registration here, because both apps are optional at runtime and an
	 * older OpenRegister does not announce sent mail at all.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	private function registerOwnedStepListeners(IRegistrationContext $context): void {
		if (class_exists(\OCA\Dossiq\Listener\FlowEmailSentListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\FlowEmailSentListener::EVENT,
				\OCA\Dossiq\Listener\FlowEmailSentListener::class
			);
		}

		if (class_exists(\OCA\Dossiq\Listener\DocumentGeneratedListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\DocumentGeneratedListener::EVENT,
				\OCA\Dossiq\Listener\DocumentGeneratedListener::class
			);
		}
	}//end registerOwnedStepListeners()

	/**
	 * Offer OpenRegister's flow engine what a case can do and no other app owns.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerFlowNodes(IRegistrationContext $context): void {
		// ADR-065: OpenRegister owns the flow engine; dossiq contributes what
		// only a case can do. Mail, notifications, object writes, decision
		// tables, documents, decisions and outbound calls are their owners'.
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
	}//end registerFlowNodes()

	/**
	 * Listen for what became of something this app sent out.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerDeliveryListeners(IRegistrationContext $context): void {
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
		// REQ: digital-post-reaches-integriq, what became of a letter. Integriq
		// dispatches DigitalPostDeliveredEvent on EVERY status change of a
		// tracked message, `failed` and `read` included, so this is how a case
		// learns that a letter did not arrive rather than going on showing the
		// last good news anyone heard. FQN string and a `class_exists` guard,
		// the same as the three above and for the same reason.
		//
		// It fails towards doing nothing: a wrong name registers nothing, the
		// stored message keeps the status the send gave it, and nobody is told
		// a letter arrived that did not.
		if (class_exists(\OCA\Dossiq\Listener\DigitalPostDeliveredListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\DigitalPostDeliveredListener::EVENT,
				\OCA\Dossiq\Listener\DigitalPostDeliveredListener::class
			);
		}
	}//end registerDeliveryListeners()

	/**
	 * Listen for work arriving from a form, a channel or a message.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerIntakeListeners(IRegistrationContext $context): void {
		// REQ-LEAF-103 (leaf-integrations): a submission of a form a case type
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

		// REQ: an-intake-message-opens-a-case. Integriq receives on a channel,
		// matches a routing rule and asks whoever owns the target to open one.
		// Nothing answered, so every form submission, messaging message and
		// public space report it routed was held with "No app opened a case
		// for this message". An FQN string for the reason the delivery seam
		// above uses one: integriq is optional, and a cross-app event class
		// name is a runtime lookup this app can only follow.
		//
		// It fails towards doing nothing. A wrong name makes `class_exists`
		// answer false, nothing registers, and integriq holds its messages
		// exactly as it does today, which for a path whose only act is
		// CREATING work is the right direction to fail in.
		if (class_exists(\OCA\Dossiq\Listener\IntakeMessageRoutedListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\IntakeMessageRoutedListener::EVENT,
				\OCA\Dossiq\Listener\IntakeMessageRoutedListener::class
			);
		}
		// REQ: inbound-messages-consume-integriq. Integriq offers every received
		// message to whichever app owns cases, with a result slot the listener
		// answers linked, created or declined. Nothing listened for it, so
		// every offer went unanswered and landed in integriq's `unassigned`.
		// That is correct behaviour on integriq's part, and the app that had
		// gone quiet was this one. FQN string and a `class_exists` guard, the
		// same as the four above and for the same reason.
		//
		// It fails towards doing nothing: a wrong name registers nothing and
		// integriq holds its messages exactly as it does today, which for a
		// path whose act is CREATING work is the right direction to fail in.
		//
		// It is a DIFFERENT event from IntakeMessageRoutedEvent above, and the
		// two listeners are not duplicates. That one answers a routing rule
		// that already decided a message belongs to a case schema; this one is
		// offered a message and its detected reference and has to decide.
		if (class_exists(\OCA\Dossiq\Listener\MessageReceivedListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\MessageReceivedListener::EVENT,
				\OCA\Dossiq\Listener\MessageReceivedListener::class
			);
		}
	}//end registerIntakeListeners()

	/**
	 * Listen for what a resident did on their own case in the portal.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPortalListeners(IRegistrationContext $context): void {
		// Issue dossiq#3142. Portaliq raises one typed fact per act a resident takes
		// on their own case (`portal.write.client`) and a separate one when
		// they withdraw it (`portal.withdraw.client`). portaliq is the source
		// and does nothing further by design; the case app tells the handler.
		// FQN string and a `class_exists` guard, the same as the integriq
		// seams above and for the same reason: portaliq is optional (ADR-046)
		// and a cross-app event class name is a runtime lookup this app can
		// only follow.
		//
		// It fails towards doing nothing: a wrong name registers nothing, the
		// resident's write still lands on the case, and the handler is simply
		// not told, which is what happened before this block existed.
		if (class_exists(\OCA\Dossiq\Listener\PortalClientWriteListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\PortalClientWriteListener::EVENT,
				\OCA\Dossiq\Listener\PortalClientWriteListener::class
			);
		}

		if (class_exists(\OCA\Dossiq\Listener\PortalClientWithdrawalListener::EVENT) === true) {
			$context->registerEventListener(
				\OCA\Dossiq\Listener\PortalClientWithdrawalListener::EVENT,
				\OCA\Dossiq\Listener\PortalClientWithdrawalListener::class
			);
		}
	}//end registerPortalListeners()
}//end class
