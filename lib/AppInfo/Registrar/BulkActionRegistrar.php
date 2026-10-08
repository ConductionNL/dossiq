<?php

/**
 * Dossiq: register the listener that declares the case bulk actions.
 *
 * Its own registrar rather than four more lines in {@see ListenerRegistrar},
 * which sits at the coupling threshold: one more class named there and the
 * file is over it. That is the threshold doing its job, and the answer is a
 * registrar per concern, which is how every other listener family here is
 * already wired.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\BulkActionRegistrationListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires dossiq's bulk actions into OpenRegister's registry.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkActionRegistrar {

	/**
	 * Register the listener that declares dossiq's four case bulk actions.
	 *
	 * Cluster 52: dossiq declares what a bulk act does to ONE case and writes
	 * no loop. The job record, the rehearsal, the progress, the per-row
	 * outcome, the cancel and the retry are OpenRegister's (ADR-022).
	 *
	 * Guarded on the event class, the same way the flow-node listener is:
	 * `::class` is a compile-time string that does not autoload, so an
	 * instance without OpenRegister still boots and simply offers no bulk
	 * actions rather than failing at registration.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		if (class_exists(\OCA\OpenRegister\Event\BulkActionRegistrationEvent::class) === false) {
			return;
		}

		$context->registerEventListener(
			\OCA\OpenRegister\Event\BulkActionRegistrationEvent::class,
			BulkActionRegistrationListener::class
		);
	}//end register()
}//end class
