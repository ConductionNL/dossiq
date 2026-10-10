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

use OCA\Dossiq\Listener\WooReportsInitialStateListener;
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
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: WooReportsInitialStateListener::class
		);
	}//end register()
}//end class
