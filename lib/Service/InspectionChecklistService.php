<?php

/**
 * Procest Inspection Checklist Service
 *
 * CRUD service for inspectionChecklist and checklistItem admin operations,
 * and inspection result submission (inspectionResult) with photo-required validation.
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
 * @spec openspec/changes/vth-module/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for admin CRUD on inspection checklists and result submission.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 */
class InspectionChecklistService
{

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param IUserSession    $userSession     The current user session
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all inspection checklists, optionally filtered by caseTypeRef.
     *
     * @param string|null $caseTypeRef Filter by case type UUID
     *
     * @return array<int, array<string, mixed>> List of checklists
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function listChecklists(?string $caseTypeRef=null): array
    {
        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_checklist_schema');

        $params = [];
        if ($caseTypeRef !== null && $caseTypeRef !== '') {
            $params['caseTypeRef'] = $caseTypeRef;
        }

        try {
            $results = $objectService->findObjects($register, $schema, $params, [], 200);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to list checklists: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return [];
        }

        return is_array($results) ? $results : [];
    }//end listChecklists()

    /**
     * Get a single inspection checklist by ID.
     *
     * @param string $id The checklist UUID
     *
     * @return array<string, mixed>|null The checklist or null if not found
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function getChecklist(string $id): ?array
    {
        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_checklist_schema');

        try {
            $result = $objectService->find($id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to get checklist: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return null;
        }

        return $this->toArray(value: $result);
    }//end getChecklist()

    /**
     * Create a new inspection checklist.
     *
     * @param array<string, mixed> $data Checklist data
     *
     * @return array<string, mixed> The created checklist
     *
     * @throws RuntimeException On validation failure or persistence error.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function createChecklist(array $data): array
    {
        if (empty($data['name']) === true) {
            throw new RuntimeException('Checklist name is required');
        }

        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_checklist_schema');

        $checklist = [
            'name'        => (string) $data['name'],
            'version'     => (int) ($data['version'] ?? 1),
            'caseTypeRef' => (string) ($data['caseTypeRef'] ?? ''),
            'items'       => $data['items'] ?? [],
            'active'      => (bool) ($data['active'] ?? false),
            'validFrom'   => $data['validFrom'] ?? date('Y-m-d'),
        ];

        try {
            $result = $objectService->saveObject($register, $schema, $checklist);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to create checklist: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Failed to create checklist: '.$e->getMessage());
        }

        return $this->toArray(value: $result);
    }//end createChecklist()

    /**
     * Update an existing inspection checklist.
     *
     * @param string               $id   The checklist UUID
     * @param array<string, mixed> $data Updated fields
     *
     * @return array<string, mixed> The updated checklist
     *
     * @throws RuntimeException On persistence error.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function updateChecklist(string $id, array $data): array
    {
        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_checklist_schema');

        try {
            $result = $objectService->saveObject($register, $schema, $data, $id);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to update checklist: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Failed to update checklist: '.$e->getMessage());
        }

        return $this->toArray(value: $result);
    }//end updateChecklist()

    /**
     * Delete an inspection checklist.
     *
     * @param string $id The checklist UUID
     *
     * @return bool True on success
     *
     * @throws RuntimeException On persistence error.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function deleteChecklist(string $id): bool
    {
        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_checklist_schema');

        try {
            $objectService->deleteObject($register, $schema, $id);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to delete checklist: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Failed to delete checklist: '.$e->getMessage());
        }

        return true;
    }//end deleteChecklist()

    /**
     * Submit an inspection result for a case + checklist.
     *
     * Validates that photo-required answers include at least one photoRef.
     *
     * @param string               $caseId      The case UUID
     * @param string               $checklistId The checklist UUID
     * @param array<string, mixed> $data        Result data including answers
     *
     * @return array<string, mixed> The persisted inspectionResult
     *
     * @throws RuntimeException When photo validation fails or persistence fails.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-4
     */
    public function submitResult(string $caseId, string $checklistId, array $data): array
    {
        $answers = $data['answers'] ?? [];
        if (is_array($answers) === false) {
            $answers = [];
        }

        $checklist = $this->getChecklist(id: $checklistId);
        if ($checklist !== null) {
            $items = $checklist['items'] ?? [];
            $this->validateAnswers(answers: $answers, items: $items);
        }

        [$objectService, $register] = $this->bootstrap();
        $schema = $this->requireConfig(key: 'inspection_result_schema');

        $user        = $this->userSession->getUser();
        $completedBy = $user !== null ? $user->getUID() : '';

        $result = [
            'case'        => $caseId,
            'checklist'   => $checklistId,
            'completedBy' => $completedBy,
            'completedAt' => date('c'),
            'answers'     => $answers,
        ];

        try {
            $persisted = $objectService->saveObject($register, $schema, $result);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to submit inspection result: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Failed to submit inspection result: '.$e->getMessage());
        }

        $this->logger->info(
            'Procest: inspection result submitted for case '.$caseId,
            ['app' => Application::APP_ID],
        );

        return $this->toArray(value: $persisted);
    }//end submitResult()

    /**
     * Validate answers against checklist items, enforcing photo requirements.
     *
     * @param array<int, array<string, mixed>> $answers Submitted answers
     * @param array<int, mixed>                $items   Checklist item definitions
     *
     * @return void
     *
     * @throws RuntimeException When a required photo is missing.
     */
    public function validateAnswers(array $answers, array $items): void
    {
        $answersByRef = [];
        foreach ($answers as $answer) {
            if (is_array($answer) === false) {
                continue;
            }

            $ref = (string) ($answer['itemRef'] ?? '');
            if ($ref !== '') {
                $answersByRef[$ref] = $answer;
            }
        }

        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            $ref  = (string) ($item['id'] ?? ($item['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            if ($type === 'photo') {
                $answer   = $answersByRef[$ref] ?? [];
                $photoRef = (string) ($answer['photoRef'] ?? '');
                if ($photoRef === '') {
                    throw new RuntimeException(
                        'Photo is required for checklist item: '.$ref
                    );
                }
            }
        }
    }//end validateAnswers()

    /**
     * Bootstrap ObjectService and the register slug.
     *
     * @return array{0: object, 1: string}
     *
     * @throws RuntimeException When OpenRegister unavailable.
     */
    private function bootstrap(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->requireConfig(key: 'register');
        return [$objectService, $register];
    }//end bootstrap()

    /**
     * Resolve a required config value.
     *
     * @param string $key The config key
     *
     * @return string
     *
     * @throws RuntimeException When value is empty.
     */
    private function requireConfig(string $key): string
    {
        $value = $this->settingsService->getConfigValue($key);
        if ($value === '') {
            throw new RuntimeException('Procest config key '.$key.' is not set');
        }

        return $value;
    }//end requireConfig()

    /**
     * Coerce an ObjectService return value to a plain array.
     *
     * @param mixed $value The raw return value
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $data = $value->jsonSerialize();
            return is_array($data) ? $data : [];
        }

        return [];
    }//end toArray()
}//end class
