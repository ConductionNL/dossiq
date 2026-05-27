<?php

/**
 * Procest Bezwaar Advice Requested Listener.
 *
 * Watches OpenRegister `bezwaar` (lifecycle) object updates for the
 * status transition to "Hoorzitting gepland" (the canonical state where
 * the bezwaar is formally referred to an advisory committee for the
 * hearing+advice track) and triggers the auto-assignment of the default
 * bezwaaradviescommissie via AdvisoryCommitteeService. The bezwaar
 * lifecycle states are owned by the sister bezwaar-lifecycle capability.
 *
 * The listener intentionally swallows infrastructure errors: failure to
 * auto-assign SHALL NOT block the parent bezwaar transition; operators
 * fall back to manual assignment via the BAC index page.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Auto-assigns the default BAC when bezwaar enters "Hoorzitting gepland".
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bezwaar-advisory-committee/specs/bezwaar-advisory-committee/spec.md
 */
class BezwaarAdviceRequestedListener implements IEventListener
{
    private const TRIGGER_STATUS = 'Hoorzitting gepland';

    /**
     * Constructor.
     *
     * @param AdvisoryCommitteeService $bacService      The BAC service
     * @param SettingsService          $settingsService Schema slug bridge
     * @param LoggerInterface          $logger          Logger
     */
    public function __construct(
        private readonly AdvisoryCommitteeService $bacService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a case update event.
     *
     * Filters: must be a `case` schema object, must have transitioned to
     * the trigger status (`status` changed in the update payload).
     *
     * @param Event $event The dispatched event
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectUpdatedEvent === false) {
            return;
        }

        try {
            $object = $this->extractObject(event: $event);
            if ($object === null) {
                return;
            }

            if ($this->isBezwaarSchema(object: $object) === false) {
                return;
            }

            $status = (string) ($object['status'] ?? '');
            if ($status !== self::TRIGGER_STATUS) {
                return;
            }

            // Avoid re-triggering: only act on the actual transition.
            $previous = $this->extractPreviousStatus(event: $event);
            if ($previous === self::TRIGGER_STATUS) {
                return;
            }

            $bezwaarId = (string) (
                $object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? ''))
            );
            if ($bezwaarId === '') {
                return;
            }

            $this->bacService->autoAssignDefaultCommittee(
                bezwaarId: $bezwaarId
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest BAC: advice-requested listener swallowed '
                .'exception: '.$e->getMessage(),
            );
        }//end try
    }//end handle()

    /**
     * Whether the object belongs to the `bezwaar` schema.
     *
     * @param array<string, mixed> $object The object payload
     *
     * @return bool
     */
    private function isBezwaarSchema(array $object): bool
    {
        $schemaSlug = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );
        if ($schemaSlug === '') {
            return false;
        }

        $candidate = (string) (
            $object['@self']['schema'] ?? ($object['schema'] ?? '')
        );

        return $candidate !== '' && (
            $candidate === $schemaSlug
            || str_ends_with($candidate, '/'.$schemaSlug)
        );
    }//end isBezwaarSchema()

    /**
     * Extract the new object payload from the update event.
     *
     * @param Event $event The event
     *
     * @return array<string, mixed>|null
     */
    private function extractObject(Event $event): ?array
    {
        foreach (['getNewObject', 'getObject'] as $method) {
            if (method_exists($event, $method) === false) {
                continue;
            }

            $value = $event->{$method}();
            $array = $this->normalise(value: $value);
            if ($array !== null) {
                return $array;
            }
        }

        return null;
    }//end extractObject()

    /**
     * Extract the previous status from the old object payload (if any).
     *
     * @param Event $event The event
     *
     * @return string Previous status or empty string when unknown
     */
    private function extractPreviousStatus(Event $event): string
    {
        if (method_exists($event, 'getOldObject') === false) {
            return '';
        }

        $array = $this->normalise(value: $event->getOldObject());
        if ($array === null) {
            return '';
        }

        return (string) ($array['status'] ?? '');
    }//end extractPreviousStatus()

    /**
     * Normalise a getter return value to an associative array.
     *
     * @param mixed $value The raw value
     *
     * @return array<string, mixed>|null
     */
    private function normalise(mixed $value): ?array
    {
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
    }//end normalise()
}//end class
