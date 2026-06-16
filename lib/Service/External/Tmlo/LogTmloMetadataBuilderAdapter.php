<?php

/**
 * Dormant default Procest TMLO / MDTO metadata builder adapter.
 *
 * Records the would-be TMLO/MDTO bundle build to the structured
 * logger and returns a synthetic BUILD_DEFERRED result carrying a
 * placeholder XML stub (clearly marked as a stub via a comment
 * processing instruction) so the surrounding lifecycle (member 04
 * document export, member 05 SIP submission) stays observable
 * until an openconnector-backed TMLO/MDTO builder source is wired
 * in via `Application::register()`. Mirrors the
 * `LogDigidSamlAdapter` dormant-default pattern used across the
 * Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Tmlo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest TMLO / MDTO metadata builder adapter.
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 */
class LogTmloMetadataBuilderAdapter implements TmloMetadataBuilderAdapterInterface
{
    /**
     * Construct the log-backed TMLO builder adapter.
     *
     * @param LoggerInterface $logger Structured logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Log the intent + synthesise a BUILD_DEFERRED stub XML.
     *
     * The stub XML is deliberately NOT XSD-valid so a downstream
     * `validateXsd()` call returns INVALID rather than silently
     * passing — that way the dormant branch never produces a
     * bundle the e-Depot would accept.
     *
     * @param string              $caseId      Zaak id.
     * @param string              $mdtoVersion Target XSD version.
     * @param array<string,mixed> $context     Build context.
     *
     * @return TmloBundleResult The build outcome.
     */
    public function buildBundle(string $caseId, string $mdtoVersion, array $context = []): TmloBundleResult
    {
        $this->logger->info(
            'Procest TMLO / MDTO buildBundle deferred (no outbound builder bound)',
            [
                'caseId'      => $caseId,
                'mdtoVersion' => $mdtoVersion,
                'context'     => $context,
            ]
        );

        // Stub XML deliberately marked + non-XSD-valid so a real
        // validator rejects it. A live binding produces real
        // MDTO/TMLO XML.
        $stub = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<!-- procest-tmlo-stub: no live MDTO/TMLO builder bound; caseId='.htmlspecialchars($caseId, ENT_XML1).' -->'
            .'<mdto:bestand xmlns:mdto="http://www.nationaalarchief.nl/mdto" stub="true"/>';

        return new TmloBundleResult(
            buildStatus: 'BUILD_DEFERRED',
            caseId: $caseId,
            metadataXml: $stub,
            metadataXsdVersion: $mdtoVersion,
            dormant: true,
            extras: [
                'reason' => 'no-outbound-builder-bound',
                'note'   => 'Bind openconnector source slug `mdto-tmlo-builder` (pin KNVI/Nationaal Archief XSD catalogue + per-tenant archiefvormerId + classificatieScheme) and override TmloMetadataBuilderAdapterInterface in Application::register() to enable XSD-valid bundle production.',
            ],
        );
    }//end buildBundle()

    /**
     * @inheritDoc
     */
    public function isDormant(): bool
    {
        return true;
    }//end isDormant()
}//end class
