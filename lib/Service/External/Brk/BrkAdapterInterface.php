<?php

/**
 * Procest BRK (Basisregistratie Kadaster) lookup port.
 *
 * The Kadaster Haal Centraal BRK Bevragen API v2 is the authoritative
 * source-of-truth for Dutch cadastral real-estate objects (percelen) —
 * kadastrale aanduiding, oppervlakte, and zakelijk-gerechtigdheid (title)
 * references. Procest ships authoritative BRP (`HaalCentraalBrpAdapter`),
 * KvK (`KvkApiAdapter`) and BAG (`BagApiAdapter`) lookup seams already;
 * this port fills the parcel/ownership gap for VTH (Vergunningen, Toezicht
 * en Handhaving) and spatial/tax case lifecycles that need a provably
 * authoritative kadastrale-aanduiding or perceel-id resolution — e.g.
 * confirming a perceel's oppervlakte or its zakelijk-gerechtigde references
 * before an enforcement decision — see
 * `openspec/changes/brk-woz-register-adapters/design.md`.
 *
 * The port is intentionally narrow — two methods returning a shared result
 * value-object — so the live binding (Kadaster BRK Bevragen v2, `X-Api-Key`
 * header) can be swapped via `Application::register()` without touching any
 * orchestrator. Until a live tier is configured, the default binding is
 * dormant: it logs the intent and returns a synthetic `LOOKUP_DEFERRED`
 * outcome so the surrounding lifecycle stays observable in test + staging
 * environments — mirrors the `BagAdapterInterface` /
 * `BrpHaalCentraalAdapterInterface` / `KvkHandelsregisterAdapterInterface`
 * dormant-default pattern exactly.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://kadaster.github.io/BRK-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brk;

/**
 * BRK (Basisregistratie Kadaster) parcel/ownership lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent and returns a synthetic
 * LOOKUP_DEFERRED outcome without contacting Kadaster.
 *
 * Activation steps for a real Kadaster binding:
 *  1. Request an API key via the BRK Bevragen registration flow
 *     (`www.kadaster.nl/zakelijk/producten/eigendom/brk-bevragen`).
 *  2. Set `integration.brk.mode` to `test` or `live`, plus
 *     `integration.brk.baseUrl` / `integration.brk.apiKey`.
 *  3. `Application::register()` already binds `BrkApiAdapter` once the
 *     mode resolves to a non-`log` tier — no further code change needed.
 *
 * @SuppressWarnings(PHPMD.LongVariable) — kadastrale-aanduiding parameter
 * names (kadastraleGemeenteCode, appartementsrechtVolgnummer) are the
 * canonical BRK domain terms; shortening them would obscure the koppelvlak.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
interface BrkAdapterInterface
{
    /**
     * Look up a kadastraal onroerende zaak (parcel) by its kadastrale
     * aanduiding (gemeentecode + sectie + perceelnummer, optionally an
     * appartementsrecht volgnummer).
     *
     * @param string              $kadastraleGemeenteCode      Kadastrale gemeentecode.
     * @param string              $sectie                      Sectie (1-2 uppercase letters).
     * @param string              $perceelnummer               Perceelnummer (1-5 digits).
     * @param string|null         $appartementsrechtVolgnummer Optional appartementsrecht
     *                                                         volgnummer (`A` + 1-4 digits).
     * @param array<string,mixed> $context                     Optional context —
     *                                                         caseId, lookupReason,
     *                                                         correlationId.
     *
     * @return BrkLookupResult The lookup outcome (status + normalized
     *                         parcel envelope, empty unless FOUND).
     *
     * @spec openspec/changes/brk-woz-register-adapters/proposal.md
     */
    public function lookupByKadastraleAanduiding(
        string $kadastraleGemeenteCode,
        string $sectie,
        string $perceelnummer,
        ?string $appartementsrechtVolgnummer=null,
        array $context=[]
    ): BrkLookupResult;

    /**
     * Look up a kadastraal onroerende zaak (parcel) by its Kadaster
     * identificatie.
     *
     * @param string              $id      BRK kadastraalOnroerendeZaak identificatie.
     * @param array<string,mixed> $context Optional context — caseId,
     *                                     lookupReason, correlationId.
     *
     * @return BrkLookupResult The lookup outcome (status + normalized
     *                         envelope, empty unless FOUND).
     *
     * @spec openspec/changes/brk-woz-register-adapters/proposal.md
     */
    public function lookupObject(string $id, array $context=[]): BrkLookupResult;

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
