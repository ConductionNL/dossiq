<?php

/**
 * Procest PublicationService
 *
 * Service for publishing besluitvorming case decisions. Publishing a besluit
 * means: (a) emitting a publication record with publishedAt + channel, and
 * (b) appending a publishedAt timestamp to the case.
 *
 * The publication record is appended to the case's `publications[]` array;
 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
 * by openconnector wiring (out of scope for the host app build).
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for besluitvorming publication.
 */
class PublicationService
{
    /**
     * Supported publication channels.
     */
    public const CHANNELS = ['gemeenteblad', 'website', 'open_raadsinformatie', 'pdc'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish a besluit on a case.
     *
     * Idempotent per (caseId, channel): re-publishing on the same channel
     * updates the publishedAt timestamp rather than appending duplicates.
     *
     * NOTE: As of procest-delegate-contract-decision, this method publishes the
     * already-recorded ZGW Besluit (fed by the decidesk Decision outcome via
     * BesluitMaterialisationService) rather than authoring a new local besluit.
     * The publication record is appended to the case's publications[] array;
     * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
     * by openconnector wiring (out of scope for the host app build).
     *
     * @param string               $caseId  The case id.
     * @param array<string, mixed> $payload The publish payload: { channel, publishedAt?, notes? }.
     *
     * @return array<string, mixed> The publication record + updated case ref.
     *
     * @throws \RuntimeException When OR is unavailable or the case can't be loaded.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-003
     */
    public function publish(string $caseId, array $payload): array
    {
        $channel = (string) ($payload['channel'] ?? 'website');
        if (in_array($channel, self::CHANNELS, true) === false) {
            throw new \InvalidArgumentException('Invalid publication channel: '.$channel);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        try {
            $obj = $objectService->find(id: $caseId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->error(
                'PublicationService::publish find failed',
                ['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
            );
            throw new \RuntimeException('Case not found: '.$caseId);
        }

        if ($obj === null) {
            throw new \RuntimeException('Case not found: '.$caseId);
        }

        if (is_array($obj) === true) {
            $case = $obj;
        } else if (method_exists($obj, 'jsonSerialize') === true) {
            $case = $obj->jsonSerialize();
        } else {
            $case = (array) $obj;
        }

        $publications = $this->extractPublications(case: $case);

        $publishedAt = (string) ($payload['publishedAt'] ?? date(format: 'c'));
        if (isset($payload['notes']) === true) {
            $notes = (string) $payload['notes'];
        } else {
            $notes = null;
        }

        // Upsert by channel — same channel publishing twice updates the timestamp.
        $upserted = false;
        foreach ($publications as $i => $pub) {
            if ((string) ($pub['channel'] ?? '') === $channel) {
                $publications[$i] = [
                    'channel'     => $channel,
                    'publishedAt' => $publishedAt,
                    'notes'       => $notes,
                ];
                $upserted         = true;
                break;
            }
        }

        if ($upserted === false) {
            $publications[] = [
                'channel'     => $channel,
                'publishedAt' => $publishedAt,
                'notes'       => $notes,
            ];
        }

        $case['publications'] = $publications;
        $case['publishedAt']  = $publishedAt;

        $objectService->saveObject(
            object: $case,
            register: $register,
            schema: $schema,
        );

        return [
            'caseId'       => $caseId,
            'channel'      => $channel,
            'publishedAt'  => $publishedAt,
            'publications' => $publications,
        ];
    }//end publish()

    /**
     * Pull the existing publications list from a case.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return array<int, array<string, mixed>> The publications list.
     */
    private function extractPublications(array $case): array
    {
        $pubs = $case['publications'] ?? [];
        if (is_string($pubs) === true) {
            $decoded = json_decode((string) $pubs, associative: true);
            if (is_array($decoded) === true) {
                $pubs = $decoded;
            } else {
                $pubs = [];
            }
        }

        if (is_array($pubs) === false) {
            return [];
        }

        $clean = [];
        foreach ($pubs as $pub) {
            if (is_array($pub) === true) {
                $clean[] = $pub;
            }
        }

        return $clean;
    }//end extractPublications()
}//end class
