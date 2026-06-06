<?php

/**
 * Procest Berichtenbox Routing Service.
 *
 * Routes a verzonden beschikking to the correct Berichtenbox channel:
 * MijnOverheid (burgers, via BSN), eHerkenning OIN (bedrijven), or print-post
 * as a fallback when the addressee has not activated a digital channel.
 *
 * Delivery itself is delegated to the existing BerichtenboxService (which owns
 * the MijnOverheid adapter); this service only resolves the channel and
 * normalises the verzending record.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Berichtenbox channel and produces a verzending record.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
 */
class BerichtenboxRoutingService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Route a beschikking to the appropriate Berichtenbox channel.
     *
     * @param array<string, mixed> $beschikking The beschikking object.
     *
     * @return array{kanaal: string, verzondenOp: string, verzondenDoor: string, berichtId: string} The verzending record.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T15
     */
    public function routeToBerichtenbox(array $beschikking): array
    {
        $geadresseerde = (array) ($beschikking['geadresseerde'] ?? []);
        $kanaal        = $this->resolveChannel(geadresseerde: $geadresseerde);

        // The berichtId is assigned by the downstream Berichtenbox provider; in
        // the absence of a live channel we derive a stable, non-identifying id
        // from the beschikking kenmerk so the delivery record is reproducible.
        $kenmerk   = (string) ($beschikking['kenmerk'] ?? ($beschikking['id'] ?? 'onbekend'));
        $berichtId = strtoupper(substr($kanaal, 0, 2)).'-'.substr(hash('sha256', $kenmerk.$kanaal), 0, 12);

        $this->logger->info(
            'BerichtenboxRoutingService: beschikking gerouteerd',
            [
                'kenmerk' => $kenmerk,
                'kanaal'  => $kanaal,
            ],
        );

        return [
            'kanaal'        => $kanaal,
            'verzondenOp'   => (new DateTimeImmutable())->format('c'),
            'verzondenDoor' => 'systeem',
            'berichtId'     => $berichtId,
        ];
    }//end routeToBerichtenbox()

    /**
     * Resolve the Berichtenbox channel for an addressee.
     *
     * @param array<string, mixed> $geadresseerde The addressee block.
     *
     * @return string The channel slug.
     */
    private function resolveChannel(array $geadresseerde): string
    {
        $type      = (string) ($geadresseerde['type'] ?? '');
        $bevestigd = ($geadresseerde['berichtenboxBevestigd'] ?? false) === true;

        if ($bevestigd === false) {
            return 'print-post';
        }

        if ($type === 'burger' && ($geadresseerde['bsn'] ?? '') !== '') {
            return 'berichtenbox-mijnoverheid';
        }

        if ($type === 'bedrijf' && ($geadresseerde['oin'] ?? '') !== '') {
            return 'berichtenbox-eherkenning';
        }

        return 'print-post';
    }//end resolveChannel()
}//end class
