<?php

/**
 * Dossiq Store Controller
 *
 * HTTP surface for the case-configuration store:
 *   - GET  /api/store/items                  — search the remote registry.
 *   - POST /api/store/items/{slug}/install   — install one item locally.
 *   - GET  /api/store/settings               — read the registry connection.
 *   - PUT  /api/store/settings               — save the registry connection.
 *
 * ADR-080: DISCOVERY is OpenRegister's. This controller INJECTS AppHost's
 * GenericStoreService, which owns the SSRF-guarded, redirect-refusing,
 * token-private fetch. Dossiq builds no registry URL and holds no HTTP client.
 *
 * Composition, NOT inheritance, and deliberately so. A cross-app `extends` is
 * resolved by the AUTOLOADER rather than the container, which breaks in three
 * places: Nextcloud's router reflects every controller during route MATCHING,
 * so an absent OpenRegister would 500 EVERY route in this app rather than only
 * the store's; the unit suite stubs OpenRegister rather than autoloading it, so
 * the subclass could not load at all; and phpstan refuses to ignore "extends
 * unknown class". An injected type-hint has none of those problems.
 *
 * INSTALL stays here, and only install. Writing a case type, its statuses and
 * its workflow into this instance is dossiq-specific and differently
 * authorized from cloning a virtual app or instantiating an agent template.
 * That is the ADR-080 Decision 3 seam.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/dossiq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ConfiguredRegistryServiceInterface;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Settings\AdminSettings;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Store search, dossiq's own install, publish, and the registry connection settings.
 *
 * @spec openspec/specs/dossiq-store-surface/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — the store's routes each reach
 *   their own engine collaborator (discovery, the publish guard, the registry
 *   write seam), and the canonical publish route has to be answered here.
 */
class StoreController extends Controller {
	/**
	 * Kebab-case slug pattern for a remote store item.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Schema slugs an installed store item is allowed to write.
	 *
	 * 🔴 THIS LIST IS A SECURITY BOUNDARY, not a convenience.
	 *
	 * Every entry is CONFIGURATION: the shape of the work, authored once by an
	 * administrator and shared between municipalities. Absent from it, and
	 * deliberately, is every RECORD schema — `case`, `task`, `decision`,
	 * `objection` and the rest. Those hold a citizen's file.
	 *
	 * Without the allowlist, "Install" is a remote write primitive: a registry,
	 * or anyone who can answer as one, could push objects straight into live
	 * case records through a button an administrator was told was safe.
	 *
	 * Widening it is a decision about what a stranger's registry may write into
	 * a municipality's data. Do not add a slug here to make an import work.
	 *
	 * @var array<int, string>
	 */
	private const INSTALLABLE_SLUGS = [
		'caseType',
		'statusType',
		'resultType',
		'roleType',
		'propertyDefinition',
		'documentType',
		'decisionType',
		'workflowTemplate',
		'inspectionChecklistTemplate',
		'automaticAction',
		'lhsMatrix',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IUserSession $userSession Current Nextcloud user session.
	 * @param IAppConfig $appConfig App config store holding the registry connection.
	 * @param GenericStoreService $storeService Engine-owned store discovery client.
	 * @param ConfiguredRegistryServiceInterface $registry Dossiq's configured object-write seam.
	 * @param StoreActionAuthorizer|null $actionAuthorizer Decides who may publish (OpenRegister); absent, nobody may.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly GenericStoreService $storeService,
		private readonly ConfiguredRegistryServiceInterface $registry,
		private readonly ?StoreActionAuthorizer $actionAuthorizer = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Dossiq's store parameters.
	 *
	 * @return StoreDescriptor
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function descriptor(): StoreDescriptor {
		return new StoreDescriptor(
			appId: AppIdentity::APP_ID,
			schema: 'case-type-template',
			defaultRegister: 'dossiq',
			cardFields: [
				'slug' => 'slug',
				'title' => 'title',
				'description' => 'description',
				'kind' => 'kind',
				'category' => 'category',
				'version' => 'version',
				'publisher' => 'publisher',
			]
		);
	}//end descriptor()

	/**
	 * Search the remote store.
	 *
	 * Login-required through an in-body guard, so an anonymous caller gets an
	 * explicit 401 rather than a login redirect. Returns normalised cards and a
	 * generic outcome, NEVER the registry URL or token.
	 *
	 * With no registry configured the engine returns `not_configured` without
	 * making a network call, which is what lets the page fall back to dossiq's
	 * built-in templates (ADR-080 Decision 4).
	 *
	 * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 *
	 * @no-admin-idor-exempt Addresses no dossiq-owned object. The query and kind
	 *   filter are forwarded to an EXTERNAL registry, so there is nothing of
	 *   another tenant's to reach by guessing an identifier.
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'cards' => []],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		return $this->searchedCards();
	}//end search()

	/**
	 * The cards the engine finds for the request's query and kind.
	 *
	 * @return JSONResponse 200 with `{outcome, cards}`, also when the registry is unreachable.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function searchedCards(): JSONResponse {
		try {
			$result = $this->storeService->search(
				descriptor: $this->descriptor(),
				query: $this->stringParam(name: 'q'),
				kind: $this->stringParam(name: 'kind')
			);
		} catch (Throwable $e) {
			return $this->searchFailed(exception: $e);
		}

		return new JSONResponse(
			data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
			statusCode: Http::STATUS_OK
		);
	}//end searchedCards()

	/**
	 * Detail to the log, generic outcome to the browser: a registry's
	 * internals are not the caller's business.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse 200 with the unreachable outcome and no cards.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function searchFailed(Throwable $exception): JSONResponse {
		$this->logger->error('Dossiq store: search failed: ' . $exception->getMessage());
		return new JSONResponse(
			data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
			statusCode: Http::STATUS_OK
		);
	}//end searchFailed()

	/**
	 * A request parameter when it is a string, else null.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * Install one store item into this instance.
	 *
	 * Administrative: the components written here are the shape of the work
	 * every handler then operates against.
	 *
	 * The write runs as the calling administrator and NOT through
	 * `runAsSystem()`. That elevation is documented as being for operations
	 * whose inputs originate from code or the app's own seed data; a store
	 * payload is neither. It comes off the network.
	 *
	 * @param string $slug The remote item slug.
	 *
	 * @return JSONResponse 200 with a per-component report; 400 for a bad slug; 404 when unresolved.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function install(string $slug): JSONResponse {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed item slug.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$item = $this->storeService->resolve(descriptor: $this->descriptor(), slug: $slug);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq store: resolve failed: ' . $e->getMessage());
			$item = null;
		}

		if ($item === null) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The store item could not be resolved.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			data: $this->installComponents(components: $this->components(item: $item)),
			statusCode: Http::STATUS_OK
		);
	}//end install()

	/**
	 * Read the components a resolved store item declares.
	 *
	 * @param array<string, mixed> $item The resolved remote object.
	 *
	 * @return array<int, array<string, mixed>> The declared components, possibly empty.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function components(array $item): array {
		$components = ($item['components'] ?? null);

		// A registry may ship the component list as a JSON string, the same way
		// dossiq's own workflowTemplate stores its steps.
		if (is_string($components) === true) {
			$components = json_decode($components, true);
		}

		if (is_array($components) === false) {
			return [];
		}

		return array_values(array_filter($components, static fn ($c): bool => is_array($c) === true));
	}//end components()

	/**
	 * Write every allowed component, and report every refused one.
	 *
	 * A refusal does not abort the install: the remaining components still
	 * arrive, and the report names what did not. An item that is half
	 * configuration and half records is a registry's mistake, not a reason to
	 * deny an administrator the half they may have.
	 *
	 * @param array<int, array<string, mixed>> $components The declared components.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function installComponents(array $components): array {
		$report = [];
		foreach ($components as $component) {
			$report[] = $this->installComponent(component: $component);
		}

		$failed = in_array(false, array_map(static fn (array $row): bool => $row['status'] === 'installed', $report), true);

		return ['success' => ($failed === false && $report !== []), 'components' => $report];
	}//end installComponents()

	/**
	 * Install one component, or say why not.
	 *
	 * @param array<string, mixed> $component One declared component.
	 *
	 * @return array{schema: string, status: string, message: string} Its report row.
	 */
	private function installComponent(array $component): array {
		$slug = (string)($component['schema'] ?? '');
		$object = ($component['object'] ?? null);

		$refusal = $this->componentRefusal(slug: $slug, object: $object);
		if ($refusal !== null) {
			return ['schema' => $slug, 'status' => 'refused', 'message' => $refusal];
		}

		return $this->writeComponent(slug: $slug, object: $object);
	}//end installComponent()

	/**
	 * Write one admitted component as a new object.
	 *
	 * @param string               $slug   The component's schema.
	 * @param array<string, mixed> $object The object it declares.
	 *
	 * @return array{schema: string, status: string, message: string} Its report row.
	 */
	private function writeComponent(string $slug, array $object): array {
		// No null guard on the lookup, and none is needed: every entry in
		// INSTALLABLE_SLUGS is a key of SLUG_TO_CONFIG_KEY, and phpstan
		// PROVES it — a slug added to the allowlist with no mapping turns
		// this back into a nullable read and fails analysis. A defensive
		// `?? null` here was dead code that hid that guarantee.
		$configKey = SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug];

		try {
			$this->registry->save(
				schemaConfigKey: $configKey,
				data: $this->asNewObject(object: $object)
			);
			return ['schema' => $slug, 'status' => 'installed', 'message' => ''];
		} catch (Throwable $e) {
			return $this->writeFailed(slug: $slug, exception: $e);
		}
	}//end writeComponent()

	/**
	 * The logged error row of a component that could not be written; the reason stays in the log.
	 *
	 * @param string    $slug      The component's schema.
	 * @param Throwable $exception The failure.
	 *
	 * @return array{schema: string, status: string, message: string}
	 */
	private function writeFailed(string $slug, Throwable $exception): array {
		$this->logger->error(
			'Dossiq store: installing {schema} failed: {error}',
			['schema' => $slug, 'error' => $exception->getMessage()]
		);
		return [
			'schema' => $slug,
			'status' => 'error',
			'message' => 'The component could not be written.',
		];
	}//end writeFailed()

	/**
	 * Why a component may not install, or null when it may.
	 *
	 * Only case configuration installs; a component must declare its object.
	 *
	 * @param string $slug   The component's schema.
	 * @param mixed  $object The object it declares.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function componentRefusal(string $slug, mixed $object): ?string {
		if (in_array($slug, self::INSTALLABLE_SLUGS, true) === false) {
			return 'Only case configuration installs from the store; '
				. 'this component names a record schema.';
		}

		if (is_array($object) === false) {
			return 'The component declares no object to install.';
		}

		return null;
	}//end componentRefusal()

	/**
	 * Strip every identity the remote payload carries, so an install CREATES.
	 *
	 * 🔴 WITHOUT THIS, "Install" IS AN OVERWRITE PRIMITIVE.
	 *
	 * OpenRegister's `saveObject()` resolves the object it is writing from the
	 * payload itself: `extractUuidAndNormalizeObject()` reads
	 * `$object['@self']['id'] ?? $object['id']` and treats a match as the uuid
	 * to UPDATE. So a store item whose component carried the uuid of this
	 * municipality's live case type would replace it rather than add one — and
	 * the write is PUT-semantic, so keys the payload omits are nulled, not left
	 * alone. The case type would not merely change, it would be gutted.
	 *
	 * The schema allowlist does not cover this. It governs WHICH schema a
	 * component may write, never whether the write creates or replaces, so an
	 * entirely legitimate `caseType` component is the attack.
	 *
	 * Identity is not the registry's to supply. An installed item is a NEW
	 * local object, and if install ever needs to be idempotent it must key on
	 * something dossiq controls rather than on a remote id.
	 *
	 * @param array<string, mixed> $object The component's object.
	 *
	 * @return array<string, mixed> The object with every identity key removed.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function asNewObject(array $object): array {
		unset($object['id'], $object['uuid'], $object['@self']);

		return $object;
	}//end asNewObject()

	/**
	 * Publish one store item through the engine.
	 *
	 * OpenRegister's canonical routes include `POST /api/store/publish`, and
	 * dossiq answers its own store routes here rather than through the
	 * engine's GenericStoreController, so this action must exist or the route
	 * fails at dispatch. The engine decides: dossiq's descriptor declares no
	 * publish fields or groups, so it answers `not_publishable`.
	 *
	 * @return JSONResponse `{outcome, slug}`; 401 anonymous, 403 not admitted, 400 without an item.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	#[NoAdminRequired]
	public function publish(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'slug' => ''],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$descriptor = $this->descriptor();
		$refusal = $this->publishRefusal(descriptor: $descriptor, user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publishItem(descriptor: $descriptor);
	}//end publish()

	/**
	 * Refuse (403) a caller the store's publish groups do not admit, and
	 * everyone when no authorizer is wired.
	 *
	 * @param StoreDescriptor $descriptor This app's store descriptor.
	 * @param IUser           $user       The caller.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may publish.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function publishRefusal(StoreDescriptor $descriptor, IUser $user): ?JSONResponse {
		if ($this->actionAuthorizer === null
			|| $this->actionAuthorizer->canPublish(descriptor: $descriptor, user: $user) === false
		) {
			return new JSONResponse(
				data: ['outcome' => 'forbidden', 'slug' => ''],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end publishRefusal()

	/**
	 * Hand the request's item to the engine; without an item it is a 400.
	 *
	 * @param StoreDescriptor $descriptor This app's store descriptor.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	private function publishItem(StoreDescriptor $descriptor): JSONResponse {
		$item = $this->request->getParam('item');
		if (is_array($item) === false) {
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_NOT_PUBLISHABLE, 'slug' => ''],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: $this->storeService->publish(descriptor: $descriptor, payload: $item),
			statusCode: Http::STATUS_OK
		);
	}//end publishItem()

	/**
	 * Read the registry connection.
	 *
	 * Reports WHETHER a token is set and never what it is. A settings form that
	 * round-trips a credential through the browser has published it to every
	 * extension the administrator happens to be running.
	 *
	 * @return JSONResponse 200 with the connection, minus the token value.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getSettings(): JSONResponse {
		return new JSONResponse(
			data: [
				'registryUrl' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'registry_url', ''),
				'registryRegister' => $this->appConfig->getValueString(
					AppIdentity::APP_ID,
					'registry_register',
					'dossiq'
				),
				'registryTokenSet' => trim(
					$this->appConfig->getValueString(AppIdentity::APP_ID, 'registry_token', '')
				) !== '',
			],
			statusCode: Http::STATUS_OK
		);
	}//end getSettings()

	/**
	 * Save the registry connection.
	 *
	 * An EMPTY token leaves the stored one untouched. The form cannot show the
	 * current token, so it posts an empty field whenever the administrator did
	 * not retype it — treating that as "clear the credential" would silently
	 * disconnect the store on every unrelated edit to the URL.
	 *
	 * @return JSONResponse 200 with the connection, minus the token value.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveSettings(): JSONResponse {
		$url = $this->request->getParam('registryUrl');
		if (is_string($url) === true) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'registry_url', trim($url));
		}

		$register = $this->request->getParam('registryRegister');
		if (is_string($register) === true && trim($register) !== '') {
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'registry_register', trim($register));
		}

		$token = $this->request->getParam('registryToken');
		if (is_string($token) === true && trim($token) !== '') {
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'registry_token', trim($token));
		}

		// The Store card on the Integrations page reads what was just saved.
		// The registry URL is the whole requirement: a registry with no address
		// is a Store that resolves nothing, whatever else is filled in.
		$this->registry->recordStoreStatus(
			registryUrl: trim($this->appConfig->getValueString(AppIdentity::APP_ID, 'registry_url', ''))
		);

		return $this->getSettings();
	}//end saveSettings()
}//end class
