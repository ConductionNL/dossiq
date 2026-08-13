<?php

/**
 * Procest WOZ (Waardering Onroerende Zaken) lookup port.
 *
 * The Kadaster Haal Centraal WOZ Bevragen API (LV-WOZ) is the authoritative
 * source-of-truth for Dutch property valuations. Procest ships
 * authoritative BRP, KvK, BAG and (with this change) BRK lookup seams
 * already; this port fills the property-valuation gap for VTH
 * (Vergunningen, Toezicht en Handhaving) and tax case lifecycles that need
 * a provably authoritative WOZ-waarde before an assessment or enforcement
 * decision.
 *
 * The public `WOZ-waardeloket` (wozwaardeloket.nl) is deliberately NOT the
 * binding target — it is a web-only individual-consultation viewer with no
 * programmatic API (confirmed during research; see
 * `openspec/changes/brk-woz-register-adapters/design.md` Decision 2). The
 * Kadaster WOZ Bevragen API is the only structured, programmatic WOZ
 * channel and is restricted to WOZ data holders (municipalities) — which
 * matches Procest's actual customer base.
 *
 * The port is intentionally narrow — three methods returning a shared
 * result value-object — so the live binding (Kadaster WOZ Bevragen,
 * `X-Api-Key` header) can be swapped via `Application::register()` without
 * touching any orchestrator. Until a live tier is configured, the default
 * binding is dormant: it logs the intent and returns a synthetic
 * `LOOKUP_DEFERRED` outcome so the surrounding lifecycle stays observable
 * in test + staging environments — mirrors the `BagAdapterInterface` /
 * `BrkAdapterInterface` dormant-default pattern exactly.
 *
 * `lookupAddress()` and `lookupByNummeraanduiding()` are deliberately thin
 * pass-throughs to the WOZ API's own address-shaped query parameters —
 * they do NOT reimplement BAG's address search/normalization pipeline
 * (see design.md Decision 3, "no BAG overlap"). A caller that already has
 * a `nummeraanduidingId` (e.g. from `BagAdapterInterface::lookupAddress()`
 * downstream, or from a case's `location.nummeraanduidingId` field — see
 * `bag-register-adapter/design.md` Decision 3) SHOULD prefer
 * `lookupByNummeraanduiding()` over `lookupAddress()`.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://kadaster.github.io/WOZ-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Woz;

/**
 * WOZ (Waardering Onroerende Zaken) property-valuation lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent and returns a synthetic
 * LOOKUP_DEFERRED outcome without contacting Kadaster.
 *
 * Activation steps for a real Kadaster binding:
 *  1. Register for WOZ Bevragen access (municipality / WOZ data holder —
 *     `www.kadaster.nl/zakelijk/producten/adressen-en-gebouwen/woz-api-bevragen`).
 *  2. Set `integration.woz.mode` to `test` or `live`, plus
 *     `integration.woz.baseUrl` / `integration.woz.apiKey`.
 *  3. `Application::register()` already binds `WozApiAdapter` once the
 *     mode resolves to a non-`log` tier — no further code change needed.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
interface WozAdapterInterface {
	/**
	 * Look up WOZ object(s) by postcode + huisnummer.
	 *
	 * @param string $postcode Dutch postcode.
	 * @param string $houseNumber House number.
	 * @param string|null $huisletter Optional house letter.
	 * @param string|null $toevoeging Optional house number
	 *                                addition.
	 * @param array<string,mixed> $context Optional context — caseId,
	 *                                     lookupReason, correlationId.
	 *
	 * @return WozLookupResult The lookup outcome (status + normalized
	 *                         envelope, empty unless FOUND).
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupAddress(
		string $postcode,
		string $houseNumber,
		?string $huisletter = null,
		?string $toevoeging = null,
		array $context = [],
	): WozLookupResult;

	/**
	 * Look up WOZ object(s) by BAG nummeraanduiding identificatie — the
	 * preferred lookup when a caller already holds one (avoids
	 * re-implementing BAG's address resolution here).
	 *
	 * @param string $addressDesignationId BAG nummeraanduiding identificatie.
	 * @param array<string,mixed> $context Optional context.
	 *
	 * @return WozLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByNummeraanduiding(string $addressDesignationId, array $context = []): WozLookupResult;

	/**
	 * Look up a single WOZ object by its wozobjectnummer.
	 *
	 * @param string $wozobjectnummer WOZ object number.
	 * @param array<string,mixed> $context Optional context.
	 *
	 * @return WozLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByWozObjectNummer(string $wozobjectnummer, array $context = []): WozLookupResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Kadaster.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function isDormant(): bool;
}//end interface
