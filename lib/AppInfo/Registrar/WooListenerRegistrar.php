<?php

/**
 * Dossiq Woo listeners registrar.
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
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\WooPagesSeenGuard;
use OCA\Dossiq\Listener\WooReportsInitialStateListener;
use OCA\Dossiq\Listener\WooStoppingRuleGuard;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

/**
 * Registers the listeners of the Woo review.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
class WooListenerRegistrar {

	/**
	 * Register the Woo review listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: WooReportsInitialStateListener::class
		);
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: WooPagesSeenGuard::class);
		}

		foreach ([ObjectUpdatingEvent::class, ObjectDeletingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: WooStoppingRuleGuard::class);
		}
	}//end register()
}//end class
