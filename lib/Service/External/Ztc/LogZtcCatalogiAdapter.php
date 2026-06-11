<?php

/**
 * Dormant default Procest ZTC / Catalogi-API adapter.
 *
 * Records the would-be ZTC resolve / import to the structured logger
 * and returns a synthetic LOOKUP_DEFERRED / IMPORT_DEFERRED result
 * so the surrounding lifecycle (cross-municipality zaaktype
 * resolution before hand-off, regional Catalogi-API zaaktype import)
 * stays observable until an openconnector-backed binding to the
 * receiving Catalogi-API is wired in via `Application::register()`.
 * Mirrors the `LogZgwExternalAdapter` dormant-default pattern used
 * across the Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Ztc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Ztc;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest ZTC / Catalogi-API adapter.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LogZtcCatalogiAdapter implements ZtcCatalogiAdapterInterface
{
    /**
     * Construct the log-backed ZTC adapter.
     *
     * @param LoggerInterface $logger Structured logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Log the resolve intent + synthesise a LOOKUP_DEFERRED result.
     *
     * @param string              $zaaktypeIdentificatie Receiver-side
     *                                                   identifier.
     * @param string              $receiverSourceSlug    openconnector
     *                                                   Source slug.
     * @param array<string,mixed> $context               Lookup context.
     *
     * @return ZtcResult The dispatch outcome.
     */
    public function resolveZaakType(string $zaaktypeIdentificatie, string $receiverSourceSlug, array $context = []): ZtcResult
    {
        $this->logger->info(
            'Procest ZTC resolveZaakType deferred (no outbound connector bound)',
            [
                'zaaktypeIdentificatie' => $zaaktypeIdentificatie,
                'receiverSourceSlug'    => $receiverSourceSlug,
                'context'               => $context,
            ]
        );

        return new ZtcResult(
            outcome: 'LOOKUP_DEFERRED',
            url: '',
            dormant: true,
            extras: [
                'reason'             => 'no-outbound-connector-bound',
                'note'               => 'Bind openconnector source slug `ztc-catalogi` (per-receiver JWT + catalogi.lezen scope) and override ZtcCatalogiAdapterInterface in Application::register() to enable real ZaakType resolution.',
                'receiverSourceSlug' => $receiverSourceSlug,
            ],
        );
    }//end resolveZaakType()

    /**
     * Log the import intent + synthesise an IMPORT_DEFERRED result.
     *
     * @param string              $zaaktypeUrl Receiver-side URL.
     * @param array<string,mixed> $context     Import context.
     *
     * @return ZtcResult The dispatch outcome.
     */
    public function importZaakType(string $zaaktypeUrl, array $context = []): ZtcResult
    {
        $this->logger->info(
            'Procest ZTC importZaakType deferred (no outbound connector bound)',
            [
                'zaaktypeUrl' => $zaaktypeUrl,
                'context'     => $context,
            ]
        );

        return new ZtcResult(
            outcome: 'IMPORT_DEFERRED',
            url: '',
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `ztc-catalogi` + catalogi.aanmaken scope on the tenant-local Catalogi-API to enable cross-tenant ZaakType import.',
            ],
        );
    }//end importZaakType()

    /**
     * @inheritDoc
     */
    public function isDormant(): bool
    {
        return true;
    }//end isDormant()
}//end class
