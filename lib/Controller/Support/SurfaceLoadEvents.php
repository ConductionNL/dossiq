<?php

/**
 * Dossiq surface load events.
 *
 * The load events a page dispatches so other apps put their scripts on it:
 * the Viewer's on every page, and the Files app's additional scripts on the
 * bare page the files browser fetches. An event whose app is not installed is
 * skipped. Split out of {@see \OCA\Dossiq\Controller\DashboardController},
 * which keeps the pages and their initial state.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Util;

/**
 * Dispatches the load events of a page.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class SurfaceLoadEvents {

	/**
	 * The load event every dossiq page dispatches: the Viewer's.
	 *
	 * The files tab on an object's page opens a file in the Viewer, over the
	 * page. The Viewer registers the handlers of other apps (the PDF viewer)
	 * on DOMContentLoaded, so it has to be on the page from the start; it is
	 * small next to the Files app's scripts.
	 *
	 * @var list<string> Class names, looked up at run time because the Viewer app is not a dependency.
	 */
	public const PAGE_SURFACE_EVENTS = [
		'OCA\\Viewer\\Event\\LoadViewer',
	];

	/**
	 * The load event behind the files browser's actions, dispatched on the bare files page only.
	 *
	 * On this event the Files app and its plugins (files_sharing, text,
	 * versions, and whatever else is installed) register their file actions
	 * and New menu entries into the shared registries `@nextcloud/files`
	 * reads. Those scripts weigh megabytes, so the dossiq pages do not
	 * dispatch it; the bundle fetches the bare files page once it opens an
	 * object's page.
	 *
	 * @var list<string> Class names, looked up at run time because the Files app is not a dependency.
	 */
	public const FILES_SURFACE_EVENTS = [
		'OCA\\Files\\Event\\LoadAdditionalScriptsEvent',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatches the load events.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Dispatch the load events of a dossiq page.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function forPage(): void {
		$this->dispatchLoadEvents(eventClasses: self::PAGE_SURFACE_EVENTS);
	}//end forPage()

	/**
	 * Add the Files app's own script and dispatch its additional-scripts event.
	 *
	 * The Files app's own actions (download, delete, rename, favourite, move
	 * and copy, open in Files) are registered by its `init` script, which
	 * only the Files page loads; every other app's arrive on the
	 * additional-scripts event. Guarded on the server's script pipeline:
	 * `Util::addScript` reaches into `OC\AppScriptDependency`, which a unit
	 * test process does not autoload.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function forFiles(): void {
		if (class_exists('OC\\AppScriptDependency') === true) {
			Util::addScript('files', 'init');
		}

		$this->dispatchLoadEvents(eventClasses: self::FILES_SURFACE_EVENTS);
	}//end forFiles()

	/**
	 * Dispatch each load event whose class exists.
	 *
	 * @param list<string> $eventClasses The event classes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function dispatchLoadEvents(array $eventClasses): void {
		foreach ($eventClasses as $eventClass) {
			if (class_exists($eventClass) === false) {
				continue;
			}

			$event = new $eventClass();
			if ($event instanceof Event) {
				$this->eventDispatcher->dispatchTyped($event);
			}
		}
	}//end dispatchLoadEvents()
}//end class
