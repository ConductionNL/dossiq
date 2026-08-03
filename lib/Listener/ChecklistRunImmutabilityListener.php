<?php

/**
 * Procest Inspection Checklist Run Immutability Listener
 *
 * Enforces REQ-IC-8: once a `inspectionChecklistRun` reaches
 * status = ingediend (or gearchiveerd), the object becomes append-only.
 * Any UPDATE that mutates protected fields after submit is rejected with
 * a RuntimeException whose message ("Checklist run is append-only") is the
 * canonical spec error string surfaced via REQ-IC-4 / REQ-IC-8 scenarios.
 *
 * The listener never blocks the initial create (ObjectCreatedEvent) and
 * lets a status transition from `in_uitvoering → ingediend` through; only
 * subsequent edits to a submitted run trigger the rejection.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reject mutations on submitted inspectionChecklistRun objects.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/inspection-checklists/tasks.md#T03
 */
class ChecklistRunImmutabilityListener implements IEventListener
{
    /**
     * Statuses past which a run is append-only.
     *
     * @var string[]
     */
    private const FROZEN_STATUSES = ['ingediend', 'gearchiveerd'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Schema slug bridge
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Inspect ObjectUpdatedEvent and reject illegal mutations.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @throws RuntimeException When a submitted run is being mutated.

     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectUpdatedEvent === false) {
            return;
        }

        try {
            if ($this->isFrozenRunMutation(event: $event) === false) {
                return;
            }

            throw new RuntimeException('Checklist run is append-only');
        } catch (RuntimeException $rejection) {
            // Re-throw rejection so OpenRegister surfaces it to the caller.
            throw $rejection;
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest: checklist immutability listener swallowed exception: '.$e->getMessage(),
            );
        }//end try
    }//end handle()

    /**
     * Whether the update mutates a checklist run that is already frozen.
     *
     * @param ObjectUpdatedEvent $event The dispatched update event
     *
     * @return bool True when the mutation must be rejected.
     */
    private function isFrozenRunMutation(ObjectUpdatedEvent $event): bool
    {
        $new = $this->extractObject(event: $event, method: 'getNewObject');
        if ($new === null) {
            return false;
        }

        if ($this->isChecklistRunSchema(object: $new) === false) {
            return false;
        }

        $old = $this->extractObject(event: $event, method: 'getOldObject');
        if ($old === null) {
            return false;
        }

        $oldStatus = (string) ($old['status'] ?? '');
        $newStatus = (string) ($new['status'] ?? '');

        // Allow first-time transition to ingediend.
        if (in_array($oldStatus, self::FROZEN_STATUSES, true) === false) {
            return false;
        }

        // Same frozen status: any change to other fields is rejected.
        if ($oldStatus === $newStatus && $this->isMaterialChange(old: $old, new: $new) === false) {
            return false;
        }

        return true;
    }//end isFrozenRunMutation()

    /**
     * Whether the supplied object belongs to the inspectionChecklistRun schema.
     *
     * @param array<string, mixed> $object Object payload
     *
     * @return bool
     */
    private function isChecklistRunSchema(array $object): bool
    {
        $expected = $this->settingsService->getConfigValue('inspection_checklist_run_schema');
        if ($expected === '') {
            return false;
        }

        $candidate = (string) (
            $object['@self']['schema'] ?? ($object['schema'] ?? '')
        );

        return $candidate !== '' && (
            $candidate === $expected
            || str_ends_with($candidate, '/'.$expected)
        );
    }//end isChecklistRunSchema()

    /**
     * Compare old/new payloads for material differences.
     *
     * Metadata-only refreshes (updatedAt etc.) are ignored; substantive
     * field mutations are rejected.
     *
     * @param array<string, mixed> $old Previous version
     * @param array<string, mixed> $new New version
     *
     * @return bool
     */
    private function isMaterialChange(array $old, array $new): bool
    {
        $protected = [
            'responses',
            'overallResult',
            'inspector',
            'templateSnapshot',
            'templateVersion',
            'template',
            'case',
            'submittedAt',
            'completedAt',
            'photos',
            'followUpType',
        ];

        foreach ($protected as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;
            if ($oldVal !== $newVal) {
                return true;
            }
        }

        return false;
    }//end isMaterialChange()

    /**
     * Extract a payload from an event by accessor method.
     *
     * @param Event  $event  Source event
     * @param string $method Accessor name (getNewObject / getOldObject)
     *
     * @return array<string, mixed>|null
     */
    private function extractObject(Event $event, string $method): ?array
    {
        if (method_exists($event, $method) === false) {
            return null;
        }

        $value = $event->{$method}();
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialised = $value->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }
        }

        return null;
    }//end extractObject()
}//end class
