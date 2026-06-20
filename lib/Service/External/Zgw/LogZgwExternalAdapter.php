<?php

/**
 * Dormant default Procest external-ZGW adapter.
 *
 * Records the would-be ZGW Zaken-API / Documenten-API push to the
 * structured logger and returns a synthetic PUSH_DEFERRED result so
 * the surrounding lifecycle (cross-municipality zaak hand-off,
 * VTH push to a regional uitvoeringsdienst) stays observable
 * until an openconnector-backed binding to the receiving ZGW stack
 * is wired in via `Application::register()`. Mirrors the
 * `LogDigidSamlAdapter` / `LogEHerkenningSamlAdapter`
 * dormant-default pattern used across the Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Zgw
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

namespace OCA\Procest\Service\External\Zgw;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest external-ZGW adapter.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LogZgwExternalAdapter implements ZgwExternalAdapterInterface
{
    /**
     * Construct the log-backed external-ZGW adapter.
     *
     * @param LoggerInterface $logger Structured logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Log the Zaken-API push intent + synthesise a PUSH_DEFERRED
     * result.
     *
     * The Zaak envelope's `rollen[]` may carry BSN values
     * (initiator role); they are deliberately REDACTED before
     * logging per AVG / WBP article 9.
     *
     * @param array<string,mixed> $zaakEnvelope Zaak payload.
     * @param array<string,mixed> $context      Push context.
     *
     * @return ZgwPushResult The dispatch outcome.
     */
    public function submitZaak(array $zaakEnvelope, array $context=[]): ZgwPushResult
    {
        $sanitised     = $this->redactBsnFromRollen(zaakEnvelope: $zaakEnvelope);
        $correlationId = (string) ($context['correlationId'] ?? 'zgw-zaak-'.bin2hex(random_bytes(6)));

        $this->logger->info(
            'Procest external-ZGW submitZaak deferred (no outbound connector bound)',
            [
                'correlationId' => $correlationId,
                'zaakEnvelope'  => $sanitised,
                'context'       => $context,
            ]
        );

        return new ZgwPushResult(
            pushStatus: 'PUSH_DEFERRED',
            receiverUrl: '',
            correlationId: $correlationId,
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `zgw-external` (per-receiver JWT signing key + Autorisaties-API scope handshake) '
                    .'and override ZgwExternalAdapterInterface in Application::register() to enable real Zaken-API push.',
            ],
        );
    }//end submitZaak()

    /**
     * Log the Documenten-API push intent + synthesise a
     * PUSH_DEFERRED result.
     *
     * The `inhoud` field (often a base64-encoded document body) is
     * deliberately stripped before logging to avoid spilling
     * document contents into the structured logger.
     *
     * @param array<string,mixed> $documentEnvelope Document payload.
     * @param array<string,mixed> $context          Push context.
     *
     * @return ZgwPushResult The dispatch outcome.
     */
    public function submitDocument(array $documentEnvelope, array $context=[]): ZgwPushResult
    {
        $sanitised = $documentEnvelope;
        if (isset($sanitised['inhoud']) === true) {
            $sanitised['inhoud'] = '[REDACTED-body-bytes='.strlen((string) $sanitised['inhoud']).']';
        }

        $correlationId = (string) ($context['correlationId'] ?? 'zgw-doc-'.bin2hex(random_bytes(6)));

        $this->logger->info(
            'Procest external-ZGW submitDocument deferred (no outbound connector bound)',
            [
                'correlationId'    => $correlationId,
                'documentEnvelope' => $sanitised,
                'context'          => $context,
            ]
        );

        return new ZgwPushResult(
            pushStatus: 'PUSH_DEFERRED',
            receiverUrl: '',
            correlationId: $correlationId,
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `zgw-external` + map receiver Documenten-API endpoint to enable real document push.',
            ],
        );
    }//end submitDocument()

    /**
     * Whether this adapter is a dormant no-op log adapter.
     *
     * @inheritDoc
     *
     * @return bool
     */
    public function isDormant(): bool
    {
        return true;
    }//end isDormant()

    /**
     * Redact the `betrokkeneIdentificatie.inpBsn` field on any
     * `natuurlijk_persoon` row inside `rollen[]`.
     *
     * @param array<string,mixed> $zaakEnvelope Zaak payload.
     *
     * @return array<string,mixed> Sanitised payload.
     */
    private function redactBsnFromRollen(array $zaakEnvelope): array
    {
        if (isset($zaakEnvelope['rollen']) === false || is_array($zaakEnvelope['rollen']) === false) {
            return $zaakEnvelope;
        }

        foreach ($zaakEnvelope['rollen'] as $idx => $rol) {
            if (is_array($rol) === false) {
                continue;
            }

            if (isset($rol['betrokkeneIdentificatie']['inpBsn']) === true) {
                $zaakEnvelope['rollen'][$idx]['betrokkeneIdentificatie']['inpBsn'] = '[REDACTED]';
            }
        }

        return $zaakEnvelope;
    }//end redactBsnFromRollen()
}//end class
