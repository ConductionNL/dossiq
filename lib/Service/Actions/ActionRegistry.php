<?php

/**
 * Dossiq Automatic Action Registry
 *
 * Per-tenant slug-based lookup for `automaticAction` objects. Mirrors the
 * status-transition-engine guard registry pattern: handlers register via DI
 * tag, the registry resolves a `(tenantId, slug)` pair to a published action
 * config, and SideEffectDispatcher uses the resolved config to invoke the
 * handler.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
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
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves `automaticAction` references for SideEffectDispatcher.
 *
 * Resolution rules (REQ-AA-2, REQ-AA-8):
 *  - Only published (`isPublished: true`) actions are returned.
 *  - Cross-tenant lookups return null AND log an error including both the
 *    requested tenantId and the action's owning tenantId.
 *  - Unknown slugs return null and are logged at error level.
 *  - A per-request in-memory cache avoids re-querying OpenRegister for the
 *    same `(tenantId, slug)` pair within a single transition dispatch.
 *
 * Action storage lives in OpenRegister under the dossiq register. CRUD is
 * delegated entirely to the OpenRegister manifest renderer
 * (`/settings/automatic-actions`) — this class is read-only.
 */
class ActionRegistry {

	/**
	 * In-process cache keyed by "{tenantId}::{slug}".
	 *
	 * Values are either the resolved action array or the sentinel `false`
	 * for known-miss (so we don't repeatedly log the same unknown slug
	 * during a single transition).
	 *
	 * @var array<string, array|false>
	 */
	private array $cache = [];

	/**
	 * Constructor for ActionRegistry.
	 *
	 * @param ContainerInterface $container DI container — used
	 *                                      to lazily resolve
	 *                                      OpenRegister's
	 *                                      ObjectService and to
	 *                                      discover handler
	 *                                      implementations.
	 * @param IAppConfig $appConfig Dossiq app config —
	 *                              provides the `register` and
	 *                              `automatic_action_schema`
	 *                              keys.
	 * @param LoggerInterface $logger PSR-3 logger for error logging on
	 *                                unknown slugs, cross-tenant
	 *                                attempts, and resolution
	 *                                failures.
	 * @param ActionHandlerLocator $handlerLocator Owns the handler table and
	 *                                             resolves handlers by `type` slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ActionHandlerLocator $handlerLocator,
	) {
	}//end __construct()

	/**
	 * Resolve a published action by tenant + slug.
	 *
	 * @param string $tenantId The tenant the resolution is being attempted in
	 *                         (derived from the case being transitioned).
	 * @param string $slug Tenant-unique action slug.
	 *
	 * @return array|null The full `automaticAction` array (with decoded
	 *                    `config`), or null on miss, unpublished, or
	 *                    cross-tenant attempt.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolve(string $tenantId, string $slug): ?array {
		$cacheKey = $tenantId . '::' . $slug;
		if (array_key_exists($cacheKey, $this->cache) === true) {
			$cached = $this->cache[$cacheKey];
			if ($cached === false) {
				return null;
			}

			return $cached;
		}

		try {
			$action = $this->findAction(slug: $slug);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActionRegistry: failed to load automaticAction',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'tenantId' => $tenantId,
					'exception' => $e->getMessage(),
				]
			);
			$this->cache[$cacheKey] = false;
			return null;
		}

		if ($action === null) {
			$this->logger->error(
				'ActionRegistry: unknown action slug',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'tenantId' => $tenantId,
					'reason' => 'not_found',
				]
			);
			$this->cache[$cacheKey] = false;
			return null;
		}

		$ownerTenant = (string)($action['tenantId'] ?? '');
		if ($ownerTenant !== '' && $ownerTenant !== $tenantId) {
			$this->logger->error(
				'ActionRegistry: cross-tenant resolution rejected',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'requestedTenant' => $tenantId,
					'ownerTenant' => $ownerTenant,
					'reason' => 'cross_tenant',
				]
			);
			$this->cache[$cacheKey] = false;
			return null;
		}

		if (($action['isPublished'] ?? false) !== true) {
			$this->logger->error(
				'ActionRegistry: action is not published',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'tenantId' => $tenantId,
					'reason' => 'unpublished',
				]
			);
			$this->cache[$cacheKey] = false;
			return null;
		}

		$action['config'] = $this->normaliseConfig(action: $action);

		$this->cache[$cacheKey] = $action;
		return $action;
	}//end resolve()

	/**
	 * Normalise a stored `config` value to a decoded array.
	 *
	 * OpenRegister stores the config as a JSON string; the dispatcher expects
	 * a decoded array. Already-decoded configs are passed through for forward
	 * compatibility, and anything unreadable degrades to an empty array.
	 *
	 * @param array $action The stored action carrying the raw `config` value.
	 *
	 * @return array The decoded config, or an empty array.
	 */
	private function normaliseConfig(array $action): array {
		$config = ($action['config'] ?? null);
		if (is_string($config) === true && $config !== '') {
			$decoded = json_decode($config, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		if (is_array($config) === false) {
			return [];
		}

		return $config;
	}//end normaliseConfig()

	/**
	 * List all actions for a tenant (used by admin UI and dry-run preview).
	 *
	 * @param string $tenantId The current tenant.
	 * @param string|null $typeFilter Optional `type` filter (e.g. only
	 *                                `sendEmail`).
	 *
	 * @return array<int, array> Tenant-owned actions; published flag is left
	 *                           on each entry so the UI can render badges.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listForTenant(string $tenantId, ?string $typeFilter = null): array {
		try {
			$all = $this->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActionRegistry: failed to list actions for tenant',
				[
					'app' => Application::APP_ID,
					'tenantId' => $tenantId,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}

		$out = [];
		foreach ($all as $action) {
			if ((string)($action['tenantId'] ?? '') !== $tenantId) {
				continue;
			}

			if ($typeFilter !== null && (string)($action['type'] ?? '') !== $typeFilter) {
				continue;
			}

			$out[] = $action;
		}

		return $out;
	}//end listForTenant()

	/**
	 * Lookup a registered handler by its `type` slug.
	 *
	 * Used by SideEffectDispatcher and by any dry-run pathway that needs to
	 * invoke a handler outside the normal transition flow.
	 *
	 * @param string $type Handler `type` slug (matches
	 *                     ActionHandlerInterface::type()).
	 *
	 * @return ActionHandlerInterface|null Null when no handler is registered.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getHandler(string $type): ?ActionHandlerInterface {
		return $this->handlerLocator->get(type: $type);
	}//end getHandler()

	/**
	 * Find a single action by slug via the OpenRegister object service.
	 *
	 * @param string $slug Action slug.
	 *
	 * @return array|null
	 */
	private function findAction(string $slug): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->appConfig->getValueString(
			Application::APP_ID,
			'register',
			''
		);
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'automatic_action_schema',
			''
		);

		if ($register === '' || $schema === '') {
			return null;
		}

		// ObjectService::findAll() takes a single $config array — the previous
		// named-argument form (register:/schema:/filters:/limit:) threw
		// "Unknown named parameter $register". Register/schema are read from
		// inside `filters`; limit is a top-level config key. Slug uniqueness is
		// enforced per-tenant at write time, so a slug match is exact here.
		$results = $objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'slug' => $slug,
				],
				'limit' => 1,
			]
		);

		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = $results[0];
		if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
			$first = $first->jsonSerialize();
		}

		return (array)$first;
	}//end findAction()

	/**
	 * Fetch all automaticAction objects across tenants (filtered downstream).
	 *
	 * @return array<int, array>
	 */
	private function fetchAll(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->appConfig->getValueString(
			Application::APP_ID,
			'register',
			''
		);
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'automatic_action_schema',
			''
		);

		if ($register === '' || $schema === '') {
			return [];
		}

		// ObjectService::findAll() takes a single $config array — see the note in
		// findAction(); register/schema are read from inside `filters`.
		$results = $objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
			]
		);

		if (is_array($results) === false) {
			return [];
		}

		$out = [];
		foreach ($results as $entry) {
			if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
				$entry = $entry->jsonSerialize();
			}

			$out[] = (array)$entry;
		}

		return $out;
	}//end fetchAll()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActionRegistry: OpenRegister ObjectService unavailable',
				[
					'app' => Application::APP_ID,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end getObjectService()
}//end class
