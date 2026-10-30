<?php

/**
 * Dossiq role permissions: tell the menu and the routes which roles the user holds.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-data-access/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Access\RolePermissions;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Provides the `role_permissions` initial state when dossiq's own page renders.
 *
 * `src/utils/permissions.js` adds these to the permissions the menu, the page
 * tabs and the route guard read, so a page a user cannot read is not offered.
 * OpenRegister's schema authorization still refuses the data itself.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/admin-data-access/spec.md
 */
class RolePermissionsInitialStateListener implements IEventListener {

	/**
	 * The initial state key.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'role_permissions';

	/**
	 * Constructor.
	 *
	 * @param IInitialState   $initialState The initial state.
	 * @param RolePermissions $roles        The user's role permissions.
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly RolePermissions $roles,
	) {
	}//end __construct()

	/**
	 * Provide the state on dossiq's own pages.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-data-access/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false
			|| $event->isLoggedIn() === false
			|| $event->getResponse()->getApp() !== Application::APP_ID
		) {
			return;
		}

		$this->initialState->provideInitialState(self::STATE_KEY, $this->roles->forCurrentUser());
	}//end handle()
}//end class
