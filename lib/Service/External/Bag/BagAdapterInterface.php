<?php

/**
 * Procest BAG (Basisregistratie Adressen en Gebouwen) lookup port.
 *
 * The Kadaster BAG API Individuele Bevragingen v2 is the authoritative
 * source-of-truth for Dutch addresses, pand (building) and verblijfsobject
 * (residential/usage object) records. Procest consumes it on VTH
 * (Vergunningen, Toezicht en Handhaving) and other spatial case lifecycles
 * that need a provably authoritative lookup — e.g. confirming a
 * `gebruiksdoel` (use purpose) or `oorspronkelijkBouwjaar` (original
 * construction year) before an enforcement decision.
 *
 * This is deliberately distinct from the existing `PdokBagService` (BAG
 * WFS open-data mirror, id-lookup only, no postcode+huisnummer search, no
 * authoritative-source guarantee) — see
 * `openspec/changes/bag-register-adapter/design.md` for the full
 * PDOK-overlap analysis. Neither service is deprecated by this port.
 *
 * The port is intentionally narrow — two methods returning a shared result
 * value-object — so the live binding (Kadaster BAG API Individuele
 * Bevragingen v2, `X-Api-Key` + `Accept-Crs` headers) can be swapped via
 * `Application::register()` without touching any orchestrator. Until a
 * live tier is configured, the default binding is dormant: it logs the
 * intent and returns a synthetic `LOOKUP_DEFERRED` outcome so the
 * surrounding lifecycle stays observable in test + staging environments —
 * mirrors the `BrpHaalCentraalAdapterInterface` /
 * `KvkHandelsregisterAdapterInterface` dormant-default pattern exactly.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://lvbag.github.io/BAG-API/Technische%20specificatie/
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Bag;

/**
 * BAG (Basisregistratie Adressen en Gebouwen) lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent and returns a synthetic
 * LOOKUP_DEFERRED outcome without contacting Kadaster.
 *
 * Activation steps for a real Kadaster binding:
 *  1. Request a free `acceptatie` (test) API key via
 *     `formulieren.kadaster.nl/aanvraag_bag_api_individuele_bevragingen_test_api_key`,
 *     or a production key for `live`.
 *  2. Set `integration.bag.mode` to `test` or `live`, plus
 *     `integration.bag.baseUrl` / `integration.bag.apiKey`.
 *  3. `Application::register()` already binds `BagApiAdapter` once the
 *     mode resolves to a non-`log` tier — no further code change needed.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
interface BagAdapterInterface {
	/**
	 * Look up address record(s) by postcode + huisnummer.
	 *
	 * @param string $postcode Dutch postcode (`1234AB` shape;
	 *                         validated by the
	 *                         implementation).
	 * @param string $houseNumber House number.
	 * @param string|null $huisletter Optional house letter.
	 * @param string|null $toevoeging Optional house number addition.
	 * @param array<string,mixed> $context Optional context —
	 *                                     caseId, lookupReason,
	 *                                     correlationId.
	 *
	 * @return BagLookupResult The lookup outcome (status + normalized
	 *                         address envelope, empty unless FOUND).
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function lookupAddress(
		string $postcode,
		string $houseNumber,
		?string $huisletter = null,
		?string $toevoeging = null,
		array $context = [],
	): BagLookupResult;

	/**
	 * Look up a BAG object (pand, verblijfsobject, or nummeraanduiding) by
	 * its identificatie.
	 *
	 * @param string $objectType `pand`, `verblijfsobject`, or
	 *                           `nummeraanduiding`.
	 * @param string $id BAG identificatie (16 digits).
	 * @param array<string,mixed> $context Optional context — caseId,
	 *                                     lookupReason, correlationId.
	 *
	 * @return BagLookupResult The lookup outcome (status + normalized
	 *                         envelope, empty unless FOUND).
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function lookupObject(string $objectType, string $id, array $context = []): BagLookupResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Kadaster.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function isDormant(): bool;
}//end interface
