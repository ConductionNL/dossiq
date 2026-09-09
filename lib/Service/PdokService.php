<?php

/**
 * Dossiq PDOK Service.
 *
 * Thin shim that fronts integriq's PDOK source adapters
 * (`PdokGeocodingClient`, `PdokWmsSourceAdapter`, `PdokWfsSourceAdapter`)
 * for dossiq backend callers. Per ADR-022 (apps-consume-OR-abstractions),
 * dossiq does NOT re-implement Locatieserver, BAG, WMS or WFS access
 * itself. The `pdok.feature_flag` key on integriq gates the live binding;
 * while the flag is `0` integriq returns synthetic deferred responses.
 *
 * INTEGRIQ IS `openconnector` RENAMED, and this file names the app through
 * {@see FleetAppId} rather than by a literal id for that reason. Both ids are
 * in the field at once, and every cross-app lookup here is duck-typed: a
 * stale id does not error, it answers false and takes the integration dark.
 *
 * Capabilities exposed:
 *   - `searchAddress(query, ...)` — address autocomplete via
 *     {@see PdokLocatieserverService::suggest()}.
 *   - `lookupAddress(id)` — single-result lookup by Locatieserver id.
 *   - `searchParcel(criteria)` — reports that integriq publishes no parcel
 *     endpoint. See the method for what closing that gap would take.
 *   - `getServiceStatus()` — health + flag introspection so the caller
 *     can render the dormant-vs-live mode.
 *
 * Error handling:
 *   - upstream 503 (PDOK unavailable / circuit open): the shim returns an
 *     empty result and exposes integriq's `message_key` via `lastWarning()`
 *     so the caller can surface the i18n string.
 *   - upstream 404 (not installed): the shim records the missing-shim
 *     warning and resolves with an empty result so the containing case form
 *     stays submittable.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/gis-integration/spec.md
 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backend-side PDOK shim consuming integriq's PDOK source adapters.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
class PdokService {
	/**
	 * The PDOK-owning app, by its CANONICAL (current) name.
	 *
	 * Passed to {@see FleetAppId}, never to `IAppManager` directly. The app
	 * renamed from `openconnector` to `integriq` and both ids are in the
	 * field, so a literal here answers false on half the fleet and the
	 * guard below then reports "not installed" about an app that is.
	 */
	public const INTEGRIQ_APP = 'integriq';

	/**
	 * Feature-flag key checked on the integriq side.
	 *
	 * Read under integriq's OWN app id, which is why it is resolved rather
	 * than hardcoded: `IAppConfig` namespaces values by app id, so reading
	 * `openconnector` on a renamed instance returns the default and the flag
	 * reads permanently off.
	 */
	public const FEATURE_FLAG_KEY = 'pdok.feature_flag';

	/**
	 * Last recorded degraded-mode warning, accessible to callers for UI
	 * surfacing. Reset to null on every successful call.
	 *
	 * @var array{messageKey:string,status:int}|null
	 */
	private ?array $lastWarning = null;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Resolves integriq's installed id.
	 * @param IAppConfig $appConfig App-config accessor.
	 * @param PdokLocatieserverService $locatieserver Existing in-app PDOK
	 *                                                ingress (cache +
	 *                                                outage tracking).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly PdokLocatieserverService $locatieserver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Autocomplete an address query via the integriq PDOK shim.
	 *
	 * @param string $query Free-text address fragment.
	 * @param array<int, string> $filters Optional Solr-style filter queries.
	 * @param int $rows Maximum suggestions to return.
	 *
	 * @return array<int, array<string,mixed>> Normalised suggestion list.
	 *
	 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
	 */
	public function searchAddress(string $query, array $filters = [], int $rows = 10): array {
		$this->lastWarning = null;
		if (strlen(trim($query)) < 3) {
			return [];
		}

		try {
			$response = $this->locatieserver->suggest($query, $filters, $rows);
		} catch (Throwable $e) {
			return $this->handleDegradedMode(error: $e, messageKey: 'pdok.unavailable');
		}

		$docs = (array)($response['response']['docs'] ?? []);
		return array_values(
			array_filter(
				$docs,
				static fn ($doc): bool => is_array($doc),
			)
		);
	}//end searchAddress()

	/**
	 * Look up a single Locatieserver result by id.
	 *
	 * @param string $id Locatieserver id returned by `searchAddress`.
	 *
	 * @return array<string,mixed>|null The normalised address envelope,
	 *                                  or null when not found / degraded.
	 *
	 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
	 */
	public function lookupAddress(string $id): ?array {
		$this->lastWarning = null;
		if ($id === '') {
			return null;
		}

		try {
			$response = $this->locatieserver->lookup($id);
		} catch (Throwable $e) {
			$this->handleDegradedMode(error: $e, messageKey: 'pdok.unavailable');
			return null;
		}

		$docs = (array)($response['response']['docs'] ?? []);
		if (is_array($docs[0] ?? null) === true) {
			return $docs[0];
		}

		return null;
	}//end lookupAddress()

	/**
	 * Search a kadastraal perceel.
	 *
	 * THERE IS NO ENDPOINT BEHIND THIS, and saying so is the point of the
	 * method. It used to call `linkToRoute('openconnector.pdok.parcel')`
	 * behind an `isInstalled('openconnector')` guard. The guard is what kept
	 * the crash off: on a current instance the old id resolves false, the
	 * method returns early, and nobody reaches the route. Correcting only the
	 * id would have turned a quiet empty list into an uncaught
	 * RouteNotFoundException, because integriq publishes exactly four PDOK
	 * routes and parcel is not among them:
	 *
	 *   GET /api/pdok/suggest      GET /api/pdok/lookup/{id}
	 *   GET /api/pdok/free         GET /api/pdok/reverse
	 *
	 * Integriq does ship `Sources\Pdok\PdokWfsSourceAdapter`, which can query
	 * `kadastralekaart:perceel`, but it is not exposed over HTTP. Closing the
	 * gap means integriq publishing a parcel route; until it does, this
	 * reports the gap rather than pretending to search.
	 *
	 * @param array<string,mixed> $criteria Search criteria, e.g. `bbox`,
	 *                                      `perceelnummer` or
	 *                                      `kadastraleAanduiding`.
	 *
	 * @return array<int, array<string,mixed>> Always empty: see above.
	 *
	 * @spec exclude reports a missing upstream endpoint; there is no dossiq
	 *       requirement it can satisfy until integriq publishes one.
	 */
	public function searchParcel(array $criteria): array {
		$this->lastWarning = null;
		$this->recordWarning(messageKey: 'pdok.parcel.unsupported', status: 501);
		$this->logger->info(
			'Dossiq PdokService: parcel search is unavailable, integriq publishes no PDOK parcel endpoint',
			['criteria' => array_keys($criteria)]
		);

		return [];
	}//end searchParcel()

	/**
	 * Report on the runtime status of the PDOK shim.
	 *
	 * @return array{
	 *     integriqInstalled: bool,
	 *     featureFlagActive: bool,
	 *     lastWarning: array{messageKey:string,status:int}|null,
	 * }
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 *      over the app-id rename map, and the answer it gives depends on the
	 *      instance rather than on any state this service holds.
	 *
	 * @spec exclude phpstan dead-code cleanup only — dropped an always-false `$route === null`
	 */
	public function getServiceStatus(): array {
		return [
			'integriqInstalled' => FleetAppId::isInstalled($this->appManager, self::INTEGRIQ_APP),
			'featureFlagActive' => $this->isFlagActive(),
			'lastWarning' => $this->lastWarning,
		];
	}//end getServiceStatus()

	/**
	 * The most recent degraded-mode warning. The caller may forward the
	 * `messageKey` to the UI for an i18n-backed banner.
	 *
	 * @return array{messageKey:string,status:int}|null
	 *
	 * @spec exclude phpstan dead-code cleanup only — dropped an always-false `$route === null`
	 */
	public function lastWarning(): ?array {
		return $this->lastWarning;
	}//end lastWarning()

	/**
	 * Whether integriq's `pdok.feature_flag` is on.
	 *
	 * Read under the id integriq is ACTUALLY installed as. `IAppConfig` keys
	 * on the app id, and integriq's own `MigrateAppConfigKeys` repair step
	 * copies this key from `openconnector` to `integriq`, so a reader pinned
	 * to the old id sees a stale copy on a migrated instance and nothing at
	 * all on a fresh one. Either way it reads the '0' default and the shim
	 * reports permanently dormant.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 *      over the app-id rename map; injecting it would add a dependency to
	 *      say the same thing.
	 */
	private function isFlagActive(): bool {
		$appId = FleetAppId::resolve($this->appManager, self::INTEGRIQ_APP);
		if ($appId === null) {
			return false;
		}

		try {
			$raw = $this->appConfig->getValueString(
				$appId,
				self::FEATURE_FLAG_KEY,
				'0'
			);
		} catch (Throwable $e) {
			$raw = '0';
		}

		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isFlagActive()

	/**
	 * Map a thrown exception into a degraded-mode warning + return value.
	 *
	 * @param Throwable $error The originating error.
	 * @param string $messageKey Default i18n key.
	 *
	 * @return array<int, array<string,mixed>> Empty list for the caller.
	 */
	private function handleDegradedMode(Throwable $error, string $messageKey): array {
		// Surface integriq's status code when available so the caller
		// can distinguish 503 (PDOK outage) from 404 (shim absent) from a
		// generic error.
		$status = 0;
		$msg = $error->getMessage();
		if (preg_match('/\b(?:HTTP|status)\s*([0-9]{3})\b/i', $msg, $matches) === 1) {
			$status = (int)$matches[1];
		}

		$effectiveKey = match ($status) {
			404 => 'pdok.integriq_missing',
			503 => 'pdok.unavailable',
			default => $messageKey,
		};

		$this->recordWarning(messageKey: $effectiveKey, status: $status);
		$this->logger->info(
			'Dossiq PdokService degraded',
			['messageKey' => $effectiveKey, 'status' => $status, 'error' => $msg]
		);
		return [];
	}//end handleDegradedMode()

	/**
	 * Record a degraded-mode warning.
	 *
	 * @param string $messageKey i18n key.
	 * @param int $status HTTP status.
	 *
	 * @return void
	 */
	private function recordWarning(string $messageKey, int $status): void {
		$this->lastWarning = ['messageKey' => $messageKey, 'status' => $status];
	}//end recordWarning()
}//end class
