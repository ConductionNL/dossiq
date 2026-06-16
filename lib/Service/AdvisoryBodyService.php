<?php

/**
 * Procest Advisory Body Service
 *
 * Service for managing advisory bodies — departments and external organizations
 * that can receive consultation requests (adviesaanvragen). Supports registry
 * CRUD, specialization-weighted search, and secure-token issuance for external
 * body access per ADR-034 and the Awb 3:5-3:9 external consultation pattern.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service for advisory body registry management.
 *
 * Advisory bodies are departments (internal) or organizations (external) that
 * can be consulted during case processing. This service exposes CRUD, weighted
 * specialization search, and secure-token issuance for external notification.
 */
class AdvisoryBodyService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all advisory bodies, optionally filtered.
     *
     * @param array<string, mixed> $filters Optional filter params
     *
     * @return array<int, array<string, mixed>> List of advisory bodies
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function findAll(array $filters=[]): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: array_merge($filters, ['_limit' => 200]),
        );

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end findAll()

    /**
     * Find a single advisory body by ID.
     *
     * @param string $id The advisory body UUID
     *
     * @return array<string, mixed>|null The advisory body or null if not found
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function findById(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        $results = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['id' => $id, '_limit' => 1],
        );

        if (is_array($results) === true && empty($results) === false) {
            return $results[0];
        }

        return null;
    }//end findById()

    /**
     * Create or update an advisory body.
     *
     * When $id is empty a new record is created; otherwise the existing record
     * is updated.
     *
     * @param array<string, mixed> $data The advisory body data
     * @param string               $id   The UUID for update (empty for create)
     *
     * @return array<string, mixed> The saved advisory body data
     *
     * @throws \RuntimeException If OpenRegister is unavailable or schema not configured
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function save(array $data, string $id=''): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Advisory body schema not configured');
        }

        if ($id !== '') {
            $result = $objectService->saveObject($register, $schema, $data, $id);
        } else {
            $result = $objectService->saveObject($register, $schema, $data);
        }

        $savedId = is_object($result) === true ? $result->getUuid() : ($id !== '' ? $id : '');

        $this->logger->info(
            'Advisory body saved: '.$savedId,
            ['app' => Application::APP_ID],
        );

        return is_array($result) === true ? $result : ['id' => $savedId];
    }//end save()

    /**
     * Delete an advisory body by ID.
     *
     * @param string $id The advisory body UUID
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If OpenRegister is unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function delete(string $id): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Advisory body schema not configured');
        }

        $objectService->deleteObject($register, $schema, $id);

        $this->logger->info(
            'Advisory body deleted: '.$id,
            ['app' => Application::APP_ID],
        );

        return true;
    }//end delete()

    /**
     * Search advisory bodies by specialization tag (case-insensitive substring).
     *
     * Returns results ranked so that bodies with a matching specialization tag
     * appear first, followed by all remaining active bodies. Bodies with
     * active=false are excluded from results.
     *
     * @param string $query Search query for specialization tags
     *
     * @return array<int, array<string, mixed>> Ranked list of advisory bodies
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function searchBySpecialization(string $query): array
    {
        $all = $this->findAll(filters: ['active' => true]);

        $lowerQuery = mb_strtolower($query);
        $matching   = [];
        $rest       = [];

        foreach ($all as $body) {
            $specializations = $body['specializations'] ?? [];
            if (is_array($specializations) === false) {
                $specializations = [];
            }

            $hasMatch = false;
            foreach ($specializations as $tag) {
                if (str_contains(mb_strtolower((string) $tag), $lowerQuery) === true) {
                    $hasMatch = true;
                    break;
                }
            }

            if ($hasMatch === true) {
                $matching[] = $body;
            } else {
                $rest[] = $body;
            }
        }

        return array_merge($matching, $rest);
    }//end searchBySpecialization()

    /**
     * Issue a secure 32-byte hex token for external body access to a consultation.
     *
     * The token is stored on the consultation object and should expire when the
     * consultation is closed. External parties access the consultation via
     * ConsultationController::publicResponse().
     *
     * @param string $consultationId The consultation UUID to issue the token for
     *
     * @return string 64-character hex string (32 random bytes)
     *
     * @throws \RuntimeException If OpenRegister is unavailable
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function issueSecureToken(string $consultationId): string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema not configured');
        }

        $token = bin2hex(random_bytes(32));

        $objectService->saveObject($register, $schema, ['secureToken' => $token], $consultationId);

        $this->logger->info(
            'Secure token issued for consultation '.$consultationId,
            ['app' => Application::APP_ID],
        );

        return $token;
    }//end issueSecureToken()

    /**
     * Send (or log) an external notification for a consultation.
     *
     * Real email delivery is delegated to an n8n webhook in production.
     * This method records the notification attempt in the application log
     * for BIO audit compliance. The actual HTTP call to n8n is intentionally
     * not implemented here — it is triggered by the x-openregister-notifications
     * schema configuration on the consultation schema.
     *
     * @param string               $consultationId   The consultation UUID
     * @param array<string, mixed> $consultationData The consultation data snapshot
     * @param string               $token            The secure access token
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
     */
    public function sendExternalNotification(
        string $consultationId,
        array $consultationData,
        string $token,
    ): void {
        $number   = $consultationData['consultationNumber'] ?? $consultationId;
        $bodyName = $consultationData['adviesInstantie'] ?? 'unknown';

        $this->logger->info(
            'External notification attempt for consultation '.$number
            .' to advisory body: '.$bodyName
            .'. Token issued; real delivery via n8n webhook.',
            [
                'app'            => Application::APP_ID,
                'consultationId' => $consultationId,
                'token_prefix'   => substr($token, 0, 8).'...',
            ],
        );
    }//end sendExternalNotification()
}//end class
