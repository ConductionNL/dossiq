<?php

/**
 * Procest MandaatEscalatieService.
 *
 * Escalation engine for mandate-denied decisions: creates an escalation
 * row pointing at the next-higher mandate holder, supports approval and
 * rejection, and auto-reroutes open escalations when the resolved
 * mandate holder changes.
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
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mandate escalation lifecycle.
 */
class MandaatEscalatieService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new escalation.
     *
     * @param string $zaakId         Case id.
     * @param string $decisionType   Decision type.
     * @param string $initiatorId    Initiating user id.
     * @param string $escalatieReden Escalation reason.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function createEscalatie(string $zaakId, string $decisionType, string $initiatorId, string $escalatieReden): array
    {
        $path = $this->resolveEscalatiePath(decisionType: $decisionType, escalatieReden: $escalatieReden);
        $row  = [
            'zaakId'          => $zaakId,
            'decisionType'    => $decisionType,
            'initiatorId'     => $initiatorId,
            'escalatieReden'  => $escalatieReden,
            'targetMandaatId' => (string) ($path['mandaatId'] ?? ''),
            'targetUserId'    => (string) ($path['userId'] ?? ''),
            'status'          => 'open',
            'createdAt'       => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];

        $saved = $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $row);
        $this->logger->info(
                'Mandaat escalation created',
                [
                    'zaakId' => $zaakId,
                    'reden'  => $escalatieReden,
                    'target' => $row['targetUserId'],
                ]
                );
        return $saved;
    }//end createEscalatie()

    /**
     * Resolve the next-higher mandate holder for a decision type.
     *
     * Walks Mandaat rows in descending plafond order; returns the first
     * holder whose mandaat applies. Returns ['mandaatId'=>'', 'userId'=>'']
     * when none is found.
     *
     * @param string $decisionType   Decision type.
     * @param string $escalatieReden Reason (currently unused — surface for
     *                               future reason-specific routing).
     *
     * @return array{mandaatId:string, userId:string}
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function resolveEscalatiePath(string $decisionType, string $escalatieReden=''): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $mSchema       = (string) $this->settingsService->getConfigValue('mandaat_schema');
        $assignSchema  = (string) $this->settingsService->getConfigValue('medewerker_rol_toewijzing_schema');
        if ($objectService === null || $register === '' || $mSchema === '' || $assignSchema === '') {
            return ['mandaatId' => '', 'userId' => ''];
        }

        try {
            $mandaten = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $mSchema,
                filters: ['status' => 'active']
            );
        } catch (\Throwable $e) {
            return ['mandaatId' => '', 'userId' => ''];
        }

        $matching = [];
        foreach ((array) $mandaten as $m) {
            if (is_array($m) === false) {
                continue;
            }

            $decTypes = (array) (($m['voorwaarden'] ?? [])['decisionTypes'] ?? []);
            if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
                continue;
            }

            $matching[] = $m;
        }

        // Sort by plafondCents descending (null/missing → 0).
        usort(
            $matching,
            static fn (array $a, array $b): int =>
                ((int) (($b['voorwaarden'] ?? [])['plafondCents'] ?? 0)) <=> ((int) (($a['voorwaarden'] ?? [])['plafondCents'] ?? 0))
        );

        foreach ($matching as $m) {
            $rolId = (string) ($m['gemandateerdeRol'] ?? '');
            if ($rolId === '') {
                continue;
            }

            try {
                $assigns = $this->searchObjectsAsArrays(
                    objectService: $objectService,
                    register: $register,
                    schema: $assignSchema,
                    filters: ['rolId' => $rolId]
                );
            } catch (\Throwable $e) {
                continue;
            }

            // Prefer primair toewijzing.
            usort(
                $assigns,
                static function (array $a, array $b): int {
                    $rank = static fn (array $r): int => match ((string) ($r['toewijzingType'] ?? 'primair')) {
                        'primair'   => 0,
                        'waarnemer' => 1,
                        'tijdelijk' => 2,
                        default      => 99,
                    };

                    return $rank($a) <=> $rank($b);
                }
            );

            foreach ((array) $assigns as $a) {
                if (is_array($a) === false) {
                    continue;
                }

                $userId = (string) ($a['userId'] ?? '');
                if ($userId !== '') {
                    return ['mandaatId' => (string) ($m['id'] ?? ''), 'userId' => $userId];
                }
            }
        }//end foreach

        return ['mandaatId' => '', 'userId' => ''];
    }//end resolveEscalatiePath()

    /**
     * Approve an open escalation.
     *
     * @param string $escalatieId         Escalation id.
     * @param string $mandaathouderUserId Approving user id (must match targetUserId).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When unauthorized or escalation missing.
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function approveEscalatie(string $escalatieId, string $mandaathouderUserId): array
    {
        $escalatie = $this->findEscalatie(escalatieId: $escalatieId);
        if ($escalatie === null) {
            throw new RuntimeException('Escalation not found: '.$escalatieId);
        }

        if ((string) ($escalatie['targetUserId'] ?? '') !== $mandaathouderUserId) {
            throw new RuntimeException('Caller is not the resolved mandate holder');
        }

        if (($escalatie['status'] ?? '') !== 'open') {
            throw new RuntimeException('Escalation not in open status');
        }

        $escalatie['status']     = 'goedgekeurd';
        $escalatie['resolvedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
        return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalatie);
    }//end approveEscalatie()

    /**
     * Reject an open escalation.
     *
     * @param string $escalatieId Escalation id.
     * @param string $reason      Rejection reason.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the escalation is missing.
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function rejectEscalatie(string $escalatieId, string $reason): array
    {
        $escalatie = $this->findEscalatie(escalatieId: $escalatieId);
        if ($escalatie === null) {
            throw new RuntimeException('Escalation not found: '.$escalatieId);
        }

        if (($escalatie['status'] ?? '') !== 'open') {
            throw new RuntimeException('Escalation not in open status');
        }

        $escalatie['status']         = 'afgewezen';
        $escalatie['afgewezenReden'] = $reason;
        $escalatie['resolvedAt']     = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
        return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalatie);
    }//end rejectEscalatie()

    /**
     * Reroute all open escalations targeting `oldUserId` to `newUserId`.
     *
     * @param string $oldUserId Old user id.
     * @param string $newUserId New user id.
     *
     * @return int Number of rerouted escalations.
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function autoRerouteOnPersonnelChange(string $oldUserId, string $newUserId): int
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('mandaat_escalatie_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return 0;
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['status' => 'open', 'targetUserId' => $oldUserId]
            );
        } catch (\Throwable $e) {
            return 0;
        }

        $count = 0;
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $row['targetUserId'] = $newUserId;
            try {
                $objectService->saveObject($register, $schema, $row);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->warning('Mandaat reroute failed', ['id' => $row['id'] ?? '', 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }//end autoRerouteOnPersonnelChange()

    /**
     * Fetch a single escalation row by id.
     *
     * @param string $escalatieId Id.
     *
     * @return array<string, mixed>|null
     */
    private function findEscalatie(string $escalatieId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('mandaat_escalatie_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $row = $objectService->find($escalatieId, register: $register, schema: $schema);
            if (is_array($row) === true) {
                return $row;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end findEscalatie()

    /**
     * Persist a payload to the configured schema.
     *
     * @param string               $schemaConfigKey Config key.
     * @param array<string, mixed> $object          Payload.
     *
     * @return array<string, mixed>
     */
    private function save(string $schemaConfigKey, array $object): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue($schemaConfigKey);
        if ($objectService === null || $register === '' || $schema === '') {
            return $object;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $object);
            if (is_array($saved) === true) {
                return $saved;
            }

            return $object;
        } catch (\Throwable $e) {
            $this->logger->error('Mandaat persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
            return $object;
        }
    }//end save()
}//end class
