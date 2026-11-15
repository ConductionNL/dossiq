<?php

/**
 * Dossiq map layer host listener
 *
 * Remembers the host of each map layer an administrator saves, so its tiles
 * may load on the case map.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Map\MapLayerHosts;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Hands the URL of a saved, active map layer to the remembered hosts.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */
class MapLayerHostListener implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The map layer schema's slug.
	 *
	 * @var string
	 */
	public const LAYER_SCHEMA = 'wmsLayer';

	/**
	 * Constructor.
	 *
	 * @param MapLayerHosts                     $hosts        The remembered hosts.
	 * @param ObjectSchemaSlugResolverInterface $slugResolver Resolves a schema id to its slug.
	 * @param IUserSession                      $userSession  Who saved the layer.
	 * @param IGroupManager                     $groupManager Whether they are an administrator.
	 */
	public function __construct(
		private readonly MapLayerHosts $hosts,
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Remember the host of a map layer that was saved active.
	 *
	 * Only a save by an administrator, or by the system (an import, occ), is
	 * remembered: the host joins the image policy of every page, which is
	 * not a thing a handler who can write a layer record may widen.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md#requirement-the-map-can-show-layers-from-an-arcgis-service-req-crs-41
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$layer = $this->savedObject(event: $event);
		if ($layer === null || $this->slugResolver->resolveFromPayload(payload: $layer) !== self::LAYER_SCHEMA) {
			return;
		}

		if (($layer['active'] ?? true) === false || $this->savedByAdministratorOrSystem() === false) {
			return;
		}

		$this->hosts->remember(url: (string)($layer['url'] ?? ''));
	}//end handle()

	/**
	 * Whether the save was made by an administrator, or outside a user session.
	 *
	 * @return bool
	 */
	private function savedByAdministratorOrSystem(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end savedByAdministratorOrSystem()
}//end class
