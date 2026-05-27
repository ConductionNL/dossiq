<?php

/**
 * Procest Bezwaar Hearing Scheduled Listener.
 *
 * Watches OpenRegister `bezwaar` (lifecycle) object updates for the
 * status transition to "Hoorzitting gepland" and optionally seeds a
 * default hearingSession via HearingService::seedDefaultHearing(). The
 * canonical hearing lifecycle states are owned by the bezwaar-hearing
 * capability; this listener does not own any transition logic.
 *
 * The listener intentionally swallows infrastructure errors: failure to
 * seed a default hearing SHALL NOT block the parent bezwaar transition.
 * Operators fall back to manual scheduling via the BezwaarHearings index
 * page.
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
use OCA\Procest\Service\Bezwaar\HearingService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds a default hearingSession when bezwaar status becomes
 * "Hoorzitting gepland".
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bezwaar-hearing/specs/bezwaar-hearing/spec.md
 */
class BezwaarHearingScheduledListener implements IEventListener
{
    private const TRIGGER_STATUS = 'Hoorzitting gepland';

    /**
     * Constructor.
     *
     * @param HearingService  $hearingService  Hearing domain service
     * @param SettingsService $settingsService Schema slug bridge
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly HearingService $hearingService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a bezwaar update event.
     *
     * @param Event $event Dispatched event
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

            $this->hearingService->seedDefaultHearing(bezwaarId: $bezwaarId);
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest hearing: scheduled listener swallowed exception: '
                .$e->getMessage(),
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
     * @param Event $event Event
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
     * @param Event $event Event
     *
     * @return string
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
     * @param mixed $value Raw value
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
