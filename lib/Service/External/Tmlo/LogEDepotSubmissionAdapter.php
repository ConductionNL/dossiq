<?php

/**
 * Dormant default Procest e-Depot submission adapter.
 *
 * Records the would-be e-Depot SIP submission to the structured
 * logger and returns a synthetic SUBMISSION_DEFERRED result so the
 * surrounding lifecycle (proof / rollback in member 06, batch
 * inspection in member 07) stays observable until an
 * openconnector-backed binding to the configured e-Depot is wired
 * in via `Application::register()`. Mirrors the
 * `LogDigidSamlAdapter` / `LogTmloMetadataBuilderAdapter`
 * dormant-default pattern used across the Procest external surface.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest e-Depot submission adapter.
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 */
class LogEDepotSubmissionAdapter implements EDepotSubmissionAdapterInterface
{
    /**
     * Construct the log-backed e-Depot adapter.
     *
     * @param LoggerInterface $logger Structured logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Log the submit intent + synthesise a SUBMISSION_DEFERRED result.
     *
     * No SIP contents are logged — only the SipBundel id pointer +
     * context. The SipBundel may carry PII via the embedded
     * documents; reading it for logging would defeat the AVG
     * protection of the staged BagIt directory.
     *
     * @param string              $sipBundelId SipBundel id pointer.
     * @param array<string,mixed> $context     Submit context.
     *
     * @return EDepotSubmissionResult The dispatch outcome.
     */
    public function submit(string $sipBundelId, array $context=[]): EDepotSubmissionResult
    {
        $transactieId = 'edepot-log-'.bin2hex(random_bytes(10));
        $this->logger->info(
            'Procest e-Depot submit deferred (no outbound connector bound)',
            [
                'overdrachtTransactieId' => $transactieId,
                'sipBundelId'            => $sipBundelId,
                'context'                => $context,
            ]
        );

        return new EDepotSubmissionResult(
            submissionStatus: 'SUBMISSION_DEFERRED',
            sipBundelId: $sipBundelId,
            archiefId: '',
            overdrachtTransactieId: $transactieId,
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `archief-edepot` (per-tenant HTTPS/SFTP/S3 credentials '
                    .'+ archief-id mapping rule) and override EDepotSubmissionAdapterInterface in '
                    .'Application::register() to enable real submission.',
            ],
        );
    }//end submit()

    /**
     * Report whether this adapter is dormant.
     *
     * @inheritDoc
     *
     * @return bool
     */
    public function isDormant(): bool
    {
        return true;
    }//end isDormant()
}//end class
