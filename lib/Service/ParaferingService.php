<?php

/**
 * Procest Parafering Service
 *
 * Service for managing the B&W parafering workflow: creating voorstellen,
 * executing parafering steps (sequential/parallel), and maintaining an
 * immutable audit trail for all parafering actions.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for managing B&W parafering workflow.
 *
 * Handles voorstel creation, parafeerroute management, sequential/parallel
 * parafering steps, and immutable audit trail recording.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ParaferingService
{
    /**
     * Voorstel status: draft.
     */
    public const STATUS_CONCEPT = 'concept';

    /**
     * Voorstel status: in parafering.
     */
    public const STATUS_IN_PARAFERING = 'in_parafering';

    /**
     * Voorstel status: returned to steller.
     */
    public const STATUS_TERUGGESTUURD = 'teruggestuurd';

    /**
     * Voorstel status: all parafering complete.
     */
    public const STATUS_GEPARAFEERD = 'geparafeerd';

    /**
     * Voorstel status: offered to college.
     */
    public const STATUS_AANGEBODEN = 'aangeboden_aan_college';

    /**
     * Voorstel status: decided by college.
     */
    public const STATUS_BESLOTEN = 'besloten';

    /**
     * Action type: paraferen (approve).
     */
    public const ACTION_PARAFEREN = 'parafered';

    /**
     * Action type: terugsturen (return with comments).
     */
    public const ACTION_TERUGSTUREN = 'teruggestuurd';

    /**
     * Action type: adviseren (non-binding advice).
     */
    public const ACTION_ADVISEREN = 'geadviseerd';

    /**
     * Step type: advisory (non-blocking).
     */
    public const STEP_TYPE_ADVISORY = 'advisory';

    /**
     * Step type: parafering (blocking, requires approval).
     */
    public const STEP_TYPE_PARAFERING = 'parafering';

    /**
     * Step type: accordering (final approval).
     */
    public const STEP_TYPE_ACCORDERING = 'accordering';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new voorstel linked to a case.
     *
     * @param array<string, mixed> $voorstelData The voorstel data (onderwerp, type, steller, caseId).
     *
     * @return array<string, mixed> The created voorstel.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function createVoorstel(array $voorstelData): array
    {
        $voorstel = [
            'id' => $voorstelData['id'] ?? $this->generateId(),
            'caseId' => $voorstelData['caseId'] ?? '',
            'type' => $voorstelData['type'] ?? 'collegeadvies',
            'onderwerp' => $voorstelData['onderwerp'] ?? '',
            'steller' => $voorstelData['steller'] ?? '',
            'afdeling' => $voorstelData['afdeling'] ?? '',
            'portefeuillehouder' => $voorstelData['portefeuillehouder'] ?? '',
            'status' => self::STATUS_CONCEPT,
            'currentStep' => 0,
            'parafeerRoute' => [],
            'auditTrail' => [],
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->logger->info(
            'Created voorstel {id} for case {caseId}',
            ['id' => $voorstel['id'], 'caseId' => $voorstel['caseId']]
        );

        return $voorstel;
    }

    /**
     * Start the parafering process on a voorstel.
     *
     * @param array<string, mixed>                         $voorstel The voorstel.
     * @param array<int, array<string, mixed>> $route     The parafeerroute (ordered steps).
     *
     * @return array<string, mixed> The updated voorstel with parafering started.
     *
     * @throws \InvalidArgumentException If voorstel is not in draft status.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function startParafering(array $voorstel, array $route): array
    {
        if ($voorstel['status'] !== self::STATUS_CONCEPT
            && $voorstel['status'] !== self::STATUS_TERUGGESTUURD
        ) {
            throw new \InvalidArgumentException(
                'Voorstel must be in concept or teruggestuurd status to start parafering'
            );
        }

        if (empty($route)) {
            throw new \InvalidArgumentException('Parafeerroute cannot be empty');
        }

        $voorstel['status'] = self::STATUS_IN_PARAFERING;
        $voorstel['currentStep'] = 0;
        $voorstel['parafeerRoute'] = $route;

        // Record in audit trail.
        $voorstel['auditTrail'][] = [
            'action' => 'started',
            'actor' => $voorstel['steller'],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'comment' => 'Parafering gestart',
            'step' => 0,
        ];

        $this->logger->info(
            'Started parafering for voorstel {id} with {stepCount} steps',
            ['id' => $voorstel['id'], 'stepCount' => count($route)]
        );

        return $voorstel;
    }

    /**
     * Execute a parafering action on a voorstel.
     *
     * @param array<string, mixed> $voorstel The voorstel.
     * @param string               $action   The action (parafered, teruggestuurd, geadviseerd).
     * @param string               $actor    The user performing the action.
     * @param string               $comment  Optional comment.
     * @param string|null          $namens   If parafering on behalf of someone else.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @throws \InvalidArgumentException If action is invalid or actor is not assigned.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function executeAction(
        array $voorstel,
        string $action,
        string $actor,
        string $comment = '',
        ?string $namens = null,
    ): array {
        if ($voorstel['status'] !== self::STATUS_IN_PARAFERING) {
            throw new \InvalidArgumentException('Voorstel is not in parafering status');
        }

        $validActions = [self::ACTION_PARAFEREN, self::ACTION_TERUGSTUREN, self::ACTION_ADVISEREN];
        if (!in_array($action, $validActions, true)) {
            throw new \InvalidArgumentException(
                'Invalid action: ' . $action . '. Valid: ' . implode(', ', $validActions)
            );
        }

        $currentStep = $voorstel['currentStep'] ?? 0;

        // Record the action in audit trail.
        $auditEntry = [
            'action' => $action,
            'actor' => $actor,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'comment' => $comment,
            'step' => $currentStep,
        ];

        if ($namens !== null) {
            $auditEntry['namens'] = $namens;
            $auditEntry['comment'] = "Geparafeerd door {$actor} namens {$namens} (mandaat). " . $comment;
        }

        $voorstel['auditTrail'][] = $auditEntry;

        // Process the action.
        if ($action === self::ACTION_TERUGSTUREN) {
            $voorstel['status'] = self::STATUS_TERUGGESTUURD;
            $this->logger->info(
                'Voorstel {id} returned by {actor}: {comment}',
                ['id' => $voorstel['id'], 'actor' => $actor, 'comment' => $comment]
            );
        } elseif ($action === self::ACTION_ADVISEREN) {
            // Advisory is non-blocking: advance to next step.
            $voorstel = $this->advanceStep($voorstel);
        } elseif ($action === self::ACTION_PARAFEREN) {
            // Check if this completes a parallel step.
            $route = $voorstel['parafeerRoute'] ?? [];
            $step = $route[$currentStep] ?? [];
            $isParallel = $step['parallel'] ?? false;

            if ($isParallel) {
                $voorstel = $this->handleParallelStep($voorstel, $actor, $currentStep);
            } else {
                $voorstel = $this->advanceStep($voorstel);
            }
        }

        return $voorstel;
    }

    /**
     * Get the full audit trail for a voorstel.
     *
     * @param array<string, mixed> $voorstel The voorstel.
     *
     * @return array<int, array<string, mixed>> The audit trail entries.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getAuditTrail(array $voorstel): array
    {
        return $voorstel['auditTrail'] ?? [];
    }

    /**
     * Get the current step information for a voorstel.
     *
     * @param array<string, mixed> $voorstel The voorstel.
     *
     * @return array<string, mixed>|null The current step, or null if parafering is complete.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getCurrentStep(array $voorstel): ?array
    {
        if ($voorstel['status'] !== self::STATUS_IN_PARAFERING) {
            return null;
        }

        $route = $voorstel['parafeerRoute'] ?? [];
        $currentStep = $voorstel['currentStep'] ?? 0;

        return $route[$currentStep] ?? null;
    }

    /**
     * Override (modify) the parafeerroute for a specific voorstel.
     *
     * @param array<string, mixed>             $voorstel The voorstel.
     * @param array<int, array<string, mixed>> $newRoute The modified route.
     * @param string                           $actor    The actor making the modification.
     * @param string                           $reason   Reason for the modification.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function overrideRoute(
        array $voorstel,
        array $newRoute,
        string $actor,
        string $reason,
    ): array {
        $voorstel['parafeerRoute'] = $newRoute;

        $voorstel['auditTrail'][] = [
            'action' => 'route_overridden',
            'actor' => $actor,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'comment' => "Parafeerroute aangepast door {$actor}, reden: {$reason}",
            'step' => $voorstel['currentStep'] ?? 0,
        ];

        $this->logger->info(
            'Parafeerroute overridden for voorstel {id} by {actor}: {reason}',
            ['id' => $voorstel['id'], 'actor' => $actor, 'reason' => $reason]
        );

        return $voorstel;
    }

    /**
     * Advance to the next step in the parafeerroute.
     *
     * @param array<string, mixed> $voorstel The voorstel.
     *
     * @return array<string, mixed> The updated voorstel.
     */
    private function advanceStep(array $voorstel): array
    {
        $route = $voorstel['parafeerRoute'] ?? [];
        $nextStep = ($voorstel['currentStep'] ?? 0) + 1;

        if ($nextStep >= count($route)) {
            // All steps completed.
            $voorstel['status'] = self::STATUS_GEPARAFEERD;
            $voorstel['auditTrail'][] = [
                'action' => 'completed',
                'actor' => 'system',
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'comment' => 'Alle paraferingstappen voltooid',
                'step' => $nextStep,
            ];
            $this->logger->info('Voorstel {id} parafering completed', ['id' => $voorstel['id']]);
        } else {
            $voorstel['currentStep'] = $nextStep;
        }

        return $voorstel;
    }

    /**
     * Handle a parallel parafering step (completes when ALL actors have parafered).
     *
     * @param array<string, mixed> $voorstel    The voorstel.
     * @param string               $actor       The actor who just parafered.
     * @param int                  $stepIndex   The step index.
     *
     * @return array<string, mixed> The updated voorstel.
     */
    private function handleParallelStep(array $voorstel, string $actor, int $stepIndex): array
    {
        $route = $voorstel['parafeerRoute'] ?? [];
        $step = $route[$stepIndex] ?? [];
        $requiredActors = $step['actors'] ?? [];

        // Check which actors have already parafered for this step.
        $auditTrail = $voorstel['auditTrail'] ?? [];
        $paraferedActors = [];
        foreach ($auditTrail as $entry) {
            if (($entry['step'] ?? -1) === $stepIndex
                && ($entry['action'] ?? '') === self::ACTION_PARAFEREN
            ) {
                $paraferedActors[] = $entry['actor'];
            }
        }

        // Check if all required actors have parafered.
        $allDone = true;
        foreach ($requiredActors as $requiredActor) {
            $actorId = is_array($requiredActor) ? ($requiredActor['id'] ?? '') : $requiredActor;
            if (!in_array($actorId, $paraferedActors, true)) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $voorstel = $this->advanceStep($voorstel);
        }

        return $voorstel;
    }

    /**
     * Generate a unique ID.
     *
     * @return string A UUID-style identifier.
     */
    private function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
