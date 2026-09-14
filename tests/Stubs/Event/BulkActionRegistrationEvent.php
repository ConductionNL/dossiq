<?php

/**
 * Stub of OpenRegister's BulkActionRegistrationEvent.
 *
 * dossiq's listener registers its four case actions on this event, so the
 * listener's test needs a class to dispatch. Mirrors openregister
 * `lib/Event/BulkActionRegistrationEvent.php`, minus the registry: the stub
 * keeps what was registered so a test can read it back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCP\EventDispatcher\Event;

/**
 * Collects the bulk actions an app declares.
 */
class BulkActionRegistrationEvent extends Event {

	/**
	 * What has been registered so far.
	 *
	 * @var array<int, BulkActionInterface>
	 */
	private array $actions = [];

	/**
	 * Register a bulk action.
	 *
	 * @param BulkActionInterface $action The action implementation.
	 *
	 * @return void
	 */
	public function registerAction(BulkActionInterface $action): void {
		$this->actions[] = $action;
	}//end registerAction()

	/**
	 * Read back what was registered.
	 *
	 * @return array<int, BulkActionInterface> The registered actions.
	 */
	public function getActions(): array {
		return $this->actions;
	}//end getActions()
}//end class
