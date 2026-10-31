<?php

/**
 * Dossiq forfeit invoice listener registrar.
 *
 * Registers the listener that raises the invoice for a forfeited enforcement
 * act when its case type says so. The procedure is case type configuration;
 * the registrar is named for what it does.
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\ForfeitInvoiceListener;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the forfeit invoice listener.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */
class ForfeitInvoiceListenerRegistrar {
	/**
	 * Register the listener on the update, the only moment an act becomes forfeited.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: ForfeitInvoiceListener::class);
	}//end register()
}//end class
