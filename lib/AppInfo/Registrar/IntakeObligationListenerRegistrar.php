<?php

/**
 * Dossiq Intake Obligation Listener Registrar.
 *
 * Wires {@see \OCA\Dossiq\Listener\IntakeObligationListener} onto the
 * created event and the activation that sends a draft: what the form asked
 * the case to wait on, such as a second signer, becomes an obligation the
 * moment the case is received (decision 271).
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\IntakeObligationListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listener that places intake obligations.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
 */
class IntakeObligationListenerRegistrar {

	/**
	 * Register on the created event and on the draft being sent.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: IntakeObligationListener::class);
		$context->registerEventListener(event: IntakeListenerRegistrar::DRAFT_SENT_EVENT, listener: IntakeObligationListener::class);
	}//end register()
}//end class
