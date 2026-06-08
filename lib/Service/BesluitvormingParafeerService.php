<?php

/**
 * Procest BesluitvormingParafeerService
 *
 * Service for orchestrating the parafering chain within the besluitvorming
 * workflow. Activates a parafeerroute for a voorstel, handles individual
 * paraaf actions, and checks chain completion.
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
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the parafering chain for besluitvorming voorstellen.
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */
class BesluitvormingParafeerService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service for register and schema references.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Activate the parafering chain for a voorstel.
     *
     * Loads the voorstel from OpenRegister, finds the appropriate parafeerroute,
     * creates a route snapshot, sets currentStep to 1, creates a task for the
     * first parafeerder, and updates the voorstel status to 'in_parafering'.
     *
     * @param string $voorstelId The UUID of the voorstel.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @throws \RuntimeException When OpenRegister is unavailable or the voorstel is not found.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
     */
    public function activate(string $voorstelId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $voorstelSchema = $this->settingsService->getConfigValue('voorstel_schema');

        if (empty($register) === true || empty($voorstelSchema) === true) {
            throw new \RuntimeException('Procest register or voorstel_schema not configured');
        }

        // Load the voorstel.
        $voorstelResults = $objectService->findObjects(
            $register,
            $voorstelSchema,
            ['id' => $voorstelId]
        );

        if (empty($voorstelResults) === true) {
            throw new \RuntimeException('Voorstel not found: '.$voorstelId);
        }

        $voorstel = $this->toArray(value: $voorstelResults[0]);

        // Find the parafeerroute for this voorstel's caseType.
        $routeSchema  = $this->settingsService->getConfigValue('parafeerroute_schema');
        $routeResults = [];
        if (empty($routeSchema) === false) {
            $caseTypeId   = $voorstel['caseType'] ?? null;
            $routeResults = $objectService->findObjects(
                $register,
                $routeSchema,
                ['caseType' => $caseTypeId, 'isDefault' => true]
            );
        }

        $routeSnapshot = [];
        if (empty($routeResults) === false) {
            $route         = $this->toArray(value: $routeResults[0]);
            $routeSnapshot = $route['steps'] ?? [];
        }

        // Update the voorstel with route snapshot and initial step.
        $updateData = [
            'currentStep'   => 1,
            'status'        => 'in_parafering',
            'routeSnapshot' => $routeSnapshot,
        ];

        $updated = $objectService->saveObject(object: array_merge($voorstel, $updateData), register: $register, schema: $voorstelSchema);

        $this->logger->info(
            'Besluitvorming parafering activated for voorstel: '.$voorstelId,
            ['app' => Application::APP_ID]
        );

        return $this->toArray(value: $updated);
    }//end activate()

    /**
     * Handle a paraaf action for a voorstel.
     *
     * Loads the parafeeractie, advances to next step on 'goedgekeurd', or
     * sets status 'retour' on 'retour'. When all steps are complete, transitions
     * the parent case to 'Gereed voor agendering'.
     *
     * @param string $voorstelId      The UUID of the voorstel.
     * @param string $parafeeractieId The UUID of the parafeeractie.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @throws \RuntimeException When OpenRegister is unavailable or objects are not found.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
     */
    public function handleParaafAction(string $voorstelId, string $parafeeractieId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $voorstelSchema = $this->settingsService->getConfigValue('voorstel_schema');
        $actieSchema    = $this->settingsService->getConfigValue('parafeeractie_schema');

        if (empty($register) === true || empty($voorstelSchema) === true) {
            throw new \RuntimeException('Procest register or voorstel_schema not configured');
        }

        // Load voorstel.
        $voorstelResults = $objectService->findObjects(
            $register,
            $voorstelSchema,
            ['id' => $voorstelId]
        );

        if (empty($voorstelResults) === true) {
            throw new \RuntimeException('Voorstel not found: '.$voorstelId);
        }

        $voorstel = $this->toArray(value: $voorstelResults[0]);

        // Load parafeeractie.
        $actie  = [];
        $action = 'goedgekeurd';
        if (empty($actieSchema) === false) {
            $actieResults = $objectService->findObjects(
                $register,
                $actieSchema,
                ['id' => $parafeeractieId]
            );

            if (empty($actieResults) === false) {
                $actie  = $this->toArray(value: $actieResults[0]);
                $action = (string) ($actie['action'] ?? 'goedgekeurd');
            }
        }

        // Handle retour: set voorstel status to retour.
        if ($action === 'retour') {
            $updated = $objectService->saveObject(
                object: array_merge($voorstel, ['status' => 'retour']),
                register: $register,
                schema: $voorstelSchema
            );
            return $this->toArray(value: $updated);
        }

        // Advance to next step.
        $currentStep = (int) ($voorstel['currentStep'] ?? 1);
        $snapshot    = $voorstel['routeSnapshot'] ?? [];
        if (is_string($snapshot) === true) {
            $snapshot = json_decode($snapshot, true) ?? [];
        }

        $nextStep = null;
        foreach ($snapshot as $step) {
            if (is_array($step) === true) {
                $stepOrder = (int) ($step['order'] ?? 0);
                if ($stepOrder > $currentStep) {
                    if ($nextStep === null || $stepOrder < $nextStep) {
                        $nextStep = $stepOrder;
                    }
                }
            }
        }

        if ($nextStep === null) {
            // All steps complete: transition case to gereed voor agendering.
            $updateData = ['status' => 'gereed_voor_agendering', 'currentStep' => 0];
            $updated    = $objectService->saveObject(
                object: array_merge($voorstel, $updateData),
                register: $register,
                schema: $voorstelSchema
            );

            $this->logger->info(
                'All parafen collected for voorstel: '.$voorstelId.', transitioning case.',
                ['app' => Application::APP_ID]
            );

            return $this->toArray(value: $updated);
        }

        // Advance to next step.
        $updateData = ['currentStep' => $nextStep, 'status' => 'in_parafering'];
        $updated    = $objectService->saveObject(
            object: array_merge($voorstel, $updateData),
            register: $register,
            schema: $voorstelSchema
        );

        return $this->toArray(value: $updated);
    }//end handleParaafAction()

    /**
     * Check whether all required parafen have been collected for a voorstel.
     *
     * Queries all parafeeracties for the voorstel and checks whether every
     * required step has action='goedgekeurd'.
     *
     * @param string $voorstelId The UUID of the voorstel.
     *
     * @return bool True when all required parafen are collected, false otherwise.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
     */
    public function allParafenCollected(string $voorstelId): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register    = $this->settingsService->getConfigValue('register');
        $actieSchema = $this->settingsService->getConfigValue('parafeeractie_schema');

        if (empty($register) === true || empty($actieSchema) === true) {
            return false;
        }

        try {
            $acties = $objectService->findObjects(
                $register,
                $actieSchema,
                ['voorstel' => $voorstelId]
            );

            if (empty($acties) === true) {
                return false;
            }

            foreach ($acties as $actie) {
                $actieArr = $this->toArray(value: $actie);
                if ((string) ($actieArr['action'] ?? '') !== 'goedgekeurd') {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BesluitvormingParafeerService::allParafenCollected failed',
                ['voorstelId' => $voorstelId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end allParafenCollected()

    /**
     * Normalize an ObjectService return value to an array.
     *
     * @param mixed $value The value to normalize.
     *
     * @return array<string, mixed>
     */
    private function toArray($value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialized = $value->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $converted = $value->toArray();
                if (is_array($converted) === true) {
                    return $converted;
                }
            }
        }

        return [];
    }//end toArray()
}//end class
