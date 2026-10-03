<?php

/**
 * Dossiq Dashboard Controller
 *
 * SPA host implemented by COMPOSITION, not inheritance. The SPA shell
 * (`page()` / `catchAll()`) is behaviourally identical to the OpenRegister
 * AppHost `GenericDashboardController` this class used to subclass, but is
 * implemented locally against OCP only. The two dossiq-specific PWA asset
 * endpoints (`serviceWorker()` / `webManifest()`) — required by the
 * mobiel-inspectie-offline Progressive Web App — remain bespoke here.
 *
 * ⚠️ DO NOT "simplify" this back into a subclass of the AppHost generic, and do
 * not `use`-import an OpenRegister class here. Nextcloud's router
 * `ReflectionClass()`es every file in `lib/Controller/` while MATCHING a route,
 * so an unresolvable parent makes EVERY route in dossiq return HTTP 500 —
 * including routes with no OpenRegister involvement at all. Dossiq does not
 * declare `<app>openregister</app>`, so an admin can create exactly that
 * configuration. `extends` is resolved by the AUTOLOADER, not the DI container,
 * so no amount of lazy registration can rescue it. See decidesk#377 / #388.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Util;

/**
 * Controller for the main Dossiq dashboard page plus the PWA assets.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
 */
class DashboardController extends Controller {

	/**
	 * App-config key, and the initial-state key it is served under.
	 *
	 * ONE spelling, read by `src/services/casePlanSource.js` under the same
	 * name. A flag whose two halves are spelled separately is a flag that is
	 * on in one place and off in the other, and nothing says so.
	 *
	 * @var string
	 */
	/**
	 * The load events that put Nextcloud's file surfaces on a page.
	 *
	 * The Viewer, and the Files app's additional scripts: on that event the
	 * Files app and its plugins (files_sharing, text, versions) register their
	 * file actions and their New menu entries, such as Request a file, into
	 * the shared registries that `@nextcloud/files` reads, so the files
	 * browser on a case page offers the same actions the Files app does. Not
	 * the Files sidebar: the sidebar's `LoadSidebar` was here too, and
	 * its scripts loaded, but on Nextcloud 34 the sidebar is a store bound to
	 * the Files app's own router and node list (`OCA.Files._sidebar`, no
	 * `OCA.Files.Sidebar.open`), so it cannot be opened from another app's
	 * page. Loading six scripts for a surface that cannot open is not worth
	 * the bytes; the files tab offers Show in Files for what the sidebar
	 * would have shown.
	 *
	 * @var list<string> Class names, looked up at run time because neither app is a dependency.
	 */
	public const FILES_SURFACE_EVENTS = [
		'OCA\\Viewer\\Event\\LoadViewer',
		'OCA\\Files\\Event\\LoadAdditionalScriptsEvent',
	];

	public const PREFER_OPENREGISTER_CASE_PLAN = 'cmmn_prefer_openregister_case_plan';

	/**
	 * App-root-relative location of the bundled PWA assets.
	 *
	 * @var string
	 */
	private const PUBLIC_DIR = __DIR__ . '/../../public';

	/**
	 * The committed feature list the Features & roadmap page reads (ADR-018).
	 *
	 * @var string
	 */
	private const FEATURES_JSON = __DIR__ . '/../../docs/features.json';

	/**
	 * Constructor.
	 *
	 * Supplies the dossiq app id so Nextcloud's DI can auto-wire this
	 * controller from its two collaborators alone.
	 *
	 * @param IRequest      $request      HTTP request.
	 * @param IInitialState $initialState Page initial state, for the roadmap feature list.
	 * @param IAppConfig    $appConfig    App configuration, for the case-plan read preference.
	 * @param IEventDispatcher $eventDispatcher The dispatcher the Files and Viewer load events go through.
	 */
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IAppConfig $appConfig,
		private readonly IEventDispatcher $eventDispatcher,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main SPA page from `templates/index.php`.
	 *
	 * `#[NoAdminRequired]` / `#[NoCSRFRequired]` were previously INHERITED from
	 * the AppHost generic; they are declared explicitly here so the auth posture
	 * is byte-for-byte unchanged by dropping the inheritance.
	 *
	 * @return TemplateResponse The rendered dossiq index template.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse {
		return $this->renderIndex();
	}//end page()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * @return TemplateResponse The rendered dossiq index template.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()

	/**
	 * Build the `index` TemplateResponse.
	 *
	 * Hands the Features & roadmap page its feature list on the way. The
	 * page (`type: roadmap`) reads `features_roadmap_features` from initial
	 * state when the manifest gives it no `config.features`; dossiq supplied
	 * neither, so the Features tab was empty while `docs/features.json` held
	 * every shipped capability (ADR-018).
	 *
	 * @return TemplateResponse The rendered dossiq index template.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
	 */
	protected function renderIndex(): TemplateResponse {
		$this->loadFilesSurfaces();
		$this->initialState->provideInitialState('features_roadmap_features', $this->roadmapFeatures());
		$this->initialState->provideInitialState(
			self::PREFER_OPENREGISTER_CASE_PLAN,
			$this->prefersOpenRegisterCasePlan()
		);

		return new TemplateResponse($this->appName, 'index');
	}//end renderIndex()

	/**
	 * Put Nextcloud's own Viewer on every dossiq page.
	 *
	 * The files tab on a case page opens a file in the Viewer, over the page,
	 * the way the Files app does, rather than rebuilding a viewer. The Viewer
	 * is a script its app adds to a page only when the page dispatches the
	 * load event; a page that does not gets none, and the tab falls back to a
	 * link into the Files app. The class is looked up by name because the
	 * Viewer app is not a dependency: an instance without it renders the page
	 * all the same. The
	 * dispatcher is required, not optional: Nextcloud's container hands a
	 * nullable parameter with a default its default, so an optional one was
	 * null on every request and nothing loaded.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	private function loadFilesSurfaces(): void {
		// The Files app's own actions (download, delete, rename, favourite,
		// move and copy, open in Files) are registered by its `init` script,
		// which only the Files page loads. Every other app's actions arrive on
		// the additional-scripts event below; this one has to be asked for.
		// Guarded on the server's script pipeline: `Util::addScript` reaches
		// into `OC\AppScriptDependency`, which a unit test process does not
		// autoload, and the controller is rendered in those.
		if (class_exists('OC\\AppScriptDependency') === true) {
			Util::addScript('files', 'init');
		}

		foreach ($this->filesSurfaceEvents() as $eventClass) {
			if (class_exists($eventClass) === false) {
				continue;
			}

			$event = new $eventClass();
			if ($event instanceof Event) {
				$this->eventDispatcher->dispatchTyped($event);
			}
		}
	}//end loadFilesSurfaces()

	/**
	 * The event classes to look up, as a run-time list.
	 *
	 * Read through a method rather than straight off the constant so the
	 * lookup is a real one: phpstan folds the constant's literal strings,
	 * finds neither class in this app's tree and calls `class_exists()` on
	 * them impossible, while on an instance with the Files and Viewer apps
	 * both exist. The declared type is what the analyser sees; the constant
	 * stays the single place the names are written.
	 *
	 * @return list<string> Class names, present or not on this instance.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	private function filesSurfaceEvents(): array {
		return self::FILES_SURFACE_EVENTS;
	}//end filesSurfaceEvents()

	/**
	 * Whether the case-plan panel prefers OpenRegister's rows over the blob.
	 *
	 * Default yes, which is the point of the bridge. Setting it to `no` is the
	 * R1 rollback of retire-cmmn-caseplanstate design.md section 4: the panel
	 * goes back to reading dossiq's own CMMN engine for every case that still
	 * carries a `casePlanState` blob, and `occ dossiq:cmmn:rollback-case-plans`
	 * regenerates a blob for the cases that no longer have one.
	 *
	 * It is a read preference and NOT a kill switch for the projection. Plans
	 * keep being created in OpenRegister at case start either way, because a
	 * case that started with no plan anywhere cannot be given one later without
	 * the migration this change has not shipped yet.
	 *
	 * @return boolean True when rows win over the blob.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	protected function prefersOpenRegisterCasePlan(): bool {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			self::PREFER_OPENREGISTER_CASE_PLAN,
			'yes'
		);

		return in_array(strtolower(trim($value)), ['no', 'false', '0', 'off'], true) === false;
	}//end prefersOpenRegisterCasePlan()

	/**
	 * The committed feature list, or an empty list when it cannot be read.
	 *
	 * The roadmap page falls back to `[]` itself, so a missing or malformed
	 * file degrades to the empty tab it showed before rather than a 500 on
	 * every page load.
	 *
	 * @return array<int, array<string, mixed>> The features from docs/features.json.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
	 */
	private function roadmapFeatures(): array {
		if (is_readable(self::FEATURES_JSON) === false) {
			return [];
		}

		$raw = file_get_contents(self::FEATURES_JSON);
		if ($raw === false) {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_array'));
	}//end roadmapFeatures()

	/**
	 * Serve the mobiel-inspectie-offline Service Worker script.
	 *
	 * Served from the app scope root with the `Service-Worker-Allowed` header
	 * so the worker may control the whole `/apps/dossiq/` scope. Public +
	 * no-CSRF because the worker must register before the user is interactive
	 * and runs without the SPA's request context.
	 *
	 * ⚠️ THE CSP ON THIS RESPONSE IS LOAD-BEARING. A Service Worker inherits
	 * the Content-Security-Policy of its OWN script response, not the one on
	 * the page that registered it. Nextcloud's default for a controller
	 * response is an EmptyContentSecurityPolicy — `default-src 'none'` with no
	 * `connect-src` — under which EVERY `fetch()` the worker makes is blocked
	 * and rejects with `TypeError: Failed to fetch`. Measured on a Nextcloud
	 * 32 instance: with the default policy, `fetch(request)`,
	 * `fetch(request.url)` and `fetch(url, {mode: 'same-origin'})` all threw
	 * inside the worker, so both strategies in `public/service-worker.js`
	 * (`cacheFirst` / `networkFirst`) could never populate a cache and always
	 * fell through to `Response.error()`. Any request the worker claimed with
	 * `respondWith()` was therefore guaranteed to fail in the page.
	 *
	 * Rate-limit rationale: PWA assets — the browser fetches these on install
	 * and on every update check, so the ceiling only exists to stop them being
	 * used as a load generator. No credential, so no brute-force counter.
	 *
	 * @return DataDownloadResponse The service-worker JavaScript.
	 *
	 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function serviceWorker(): DataDownloadResponse {
		$body = $this->readPublicAsset(name: 'service-worker.js');
		$status = Http::STATUS_OK;
		if ($body === '') {
			$status = Http::STATUS_NOT_FOUND;
		}

		$response = new DataDownloadResponse($body, 'service-worker.js', 'application/javascript', $status);
		$response->addHeader('Service-Worker-Allowed', '/');

		$csp = new EmptyContentSecurityPolicy();
		// The offline sync strategy talks back to this Nextcloud.
		$csp->addAllowedConnectDomain('\'self\'');
		// The tile strategy talks to the BRT achtergrondkaart WMTS host, which
		// is the only third-party host public/service-worker.js will fetch.
		$csp->addAllowedConnectDomain('https://service.pdok.nl');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end serviceWorker()

	/**
	 * Serve the PWA web app manifest.
	 *
	 * @return DataDownloadResponse The web app manifest JSON.
	 *
	 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function webManifest(): DataDownloadResponse {
		$body = $this->readPublicAsset(name: 'manifest.webmanifest');
		$status = Http::STATUS_OK;
		if ($body === '') {
			$status = Http::STATUS_NOT_FOUND;
		}

		return new DataDownloadResponse($body, 'manifest.webmanifest', 'application/manifest+json', $status);
	}//end webManifest()

	/**
	 * Read a static asset shipped under the app's public directory.
	 *
	 * Returns an empty string when the asset is absent or unreadable; callers
	 * translate that into a 404. Guarding with is_file()/is_readable() keeps
	 * the missing-asset path free of PHP warnings without an `@` operator.
	 *
	 * @param string $name Bare file name inside the public directory.
	 *
	 * @return string The asset contents, or '' when it cannot be read.
	 */
	private function readPublicAsset(string $name): string {
		$path = self::PUBLIC_DIR . '/' . $name;
		if (is_file($path) === false || is_readable($path) === false) {
			return '';
		}

		return (string)file_get_contents($path);
	}//end readPublicAsset()
}//end class
