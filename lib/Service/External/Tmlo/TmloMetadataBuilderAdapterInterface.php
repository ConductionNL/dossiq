<?php

/**
 * Procest TMLO / MDTO metadata builder port.
 *
 * Toepassingsprofiel Metadatering Lokale Overheden (TMLO 1.2.1) and
 * its successor Metadata Decreet TerOverdracht (MDTO 1.1) are the
 * XSD-bound metadata standards Dutch municipalities use to label
 * archief-overdracht-bundles for transfer to a certified e-Depot.
 * The `archief-edepot-handover` chain materialises one SipBundel per
 * zaak; building the TMLO/MDTO XML envelope (`identificatie`,
 * `aggregatieniveau`, `naam`, `classificatie`, `dekkingInTijd`,
 * `beperkingGebruik`, `bewaartermijn`, `eventGeschiedenis`, `author`)
 * is a standards-bound XML transformation that the production
 * implementation will source from a maintained
 * `mdto-tmlo-builder` library shipped as an openconnector helper
 * source.
 *
 * The port is intentionally narrow — one `buildBundle()` method
 * returning an XML+XSD-version envelope — so the production binding
 * (openconnector source slug `mdto-tmlo-builder`, KNVI/Nationaal
 * Archief XSD catalog) can be swapped in via
 * `Application::register()` without touching the
 * `archief-edepot-handover-03-metadata-bundling` orchestrator.
 *
 * Until that binding is configured, the default binding is dormant:
 * it logs the build intent and returns a synthetic
 * `BUILD_DEFERRED` outcome carrying a placeholder MDTO XML stub so
 * the surrounding lifecycle (member 03→04→05→06 of the
 * archief-edepot-handover chain) stays observable in test +
 * staging environments without producing an XSD-valid bundle.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Tmlo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://www.nationaalarchief.nl/archiveren/kennisbank/mdto
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

/**
 * TMLO / MDTO metadata builder port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the build intent and returns a
 * synthetic BUILD_DEFERRED outcome so the surrounding lifecycle can
 * advance into `pending-mdto-builder` without producing an
 * XSD-valid bundle.
 *
 * Activation steps for a real TMLO/MDTO builder binding:
 *  1. Pin the KNVI/Nationaal Archief XSD catalogue (TMLO 1.2.1
 *     and/or MDTO 1.1) in openconnector under source slug
 *     `mdto-tmlo-builder`.
 *  2. Provision the per-tenant `archiefvormer` identifier and the
 *     classification scheme (`zaakgericht-werken-archief-pid` for
 *     municipalities, or the tenant's own scheme).
 *  3. Override the TmloMetadataBuilderAdapterInterface DI binding
 *     in `Application::register()` to the implementation backed
 *     by the openconnector source.
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 */
interface TmloMetadataBuilderAdapterInterface
{
    /**
     * Build a TMLO / MDTO metadata bundle for a SipBundel.
     *
     * @param string              $caseId      The zaak id the bundle is
     *                                         being built for.
     * @param string              $mdtoVersion `mdto-1.1` or `tmlo-1.2.1`.
     * @param array<string,mixed> $context     Optional context —
     *                                         sipBundelId,
     *                                         archiefvormerId,
     *                                         classificatieScheme,
     *                                         correlationId.
     *
     * @return TmloBundleResult The build outcome — `metadataXml` is the
     *                          full XML payload; `metadataXsdVersion`
     *                          echoes the XSD the builder validated
     *                          against.
     */
    public function buildBundle(string $caseId, string $mdtoVersion, array $context = []): TmloBundleResult;

    /**
     * Whether the adapter is dormant — i.e. wired but not running an
     * XSD-valid TMLO / MDTO builder.
     *
     * @return bool TRUE when the adapter is a log-only stub.
     */
    public function isDormant(): bool;
}//end interface
