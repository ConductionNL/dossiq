<?php

/**
 * Procest Bezwaar Decision Listener.
 *
 * Observes OpenRegister object events on the `bezwaar` schema and
 * blocks a transition into status "Beslissing op bezwaar" when no
 * published bezwaarDecision exists for the case. The listener is a
 * pure guard: when the precondition is satisfied it is a no-op; when
 * the precondition fails it reverts the status to the previous value
 * via OpenRegister (no bespoke status-machine logic — the
 * status-transition-engine remains the single owner of transition
 * mechanics, this guard just enforces the published-decision
 * requirement).
 *
 * Failures of the guard derivation itself are swallowed (logged) so
 * that bezwaar persistence is never blocked by a defective lookup.
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
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards bezwaar transitions into "Beslissing op bezwaar" by requiring
 * a published bezwaarDecision to exist for the bezwaar.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/bezwaar-decision/specs/bezwaar-decision/spec.md
 */
class BezwaarDecisionListener implements IEventListener
{
    /**
     * Target status the guard protects.
     */
    private const PROTECTED_STATUS = 'Beslissing op bezwaar';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Schema slug bridge.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a bezwaar update event.
     *
     * @param Event $event Event instance.
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
            if ($status !== self::PROTECTED_STATUS) {
                return;
            }

            if ($this->hasPublishedDecision(bezwaar: $object) === true) {
                return;
            }

            // Guard violated — revert.
            $this->revertStatus(bezwaar: $object, event: $event);
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest bezwaar-decision: guard derivation swallowed '
                .'exception: '.$e->getMessage()
            );
        }//end try
    }//end handle()

    /**
     * Decide whether the bezwaar has at least one published
     * bezwaarDecision.
     *
     * @param array<string, mixed> $bezwaar The bezwaar payload.
     *
     * @return bool
     */
    private function hasPublishedDecision(array $bezwaar): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return true;
        }

        $register       = $this->settingsService->getConfigValue(
            key: 'register'
        );
        $decisionSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_decision_schema'
        );
        if ($register === '' || $decisionSchema === '') {
            return true;
        }

        $bezwaarId = (string) ($bezwaar['id'] ?? ($bezwaar['uuid'] ?? ''));
        if ($bezwaarId === '') {
            return true;
        }

        // A bezwaarDecision is "decided" either when it carries the legacy
        // local `status:published` (historical records) OR when it has been
        // delegated to decidesk and carries a `decisionRef` (the besluit is the
        // decidesk outcome — procest-delegate-remaining-decisions-to-decidesk,
        // REQ-PDRD-001/REQ-PDRD-003). Both satisfy the published-decision guard.
        try {
            $all = $objectService->findAll(
                $register,
                $decisionSchema,
                ['bezwaar' => $bezwaarId]
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest bezwaar-decision: lookup failed — allowing '
                .'transition by default: '.$e->getMessage()
            );
            return true;
        }

        if (is_array($all) === false) {
            return false;
        }

        foreach ($all as $decision) {
            if (is_array($decision) === false) {
                continue;
            }

            $status      = (string) ($decision['status'] ?? '');
            $decisionRef = (string) ($decision['decisionRef'] ?? '');
            if ($status === 'published' || $decisionRef !== '') {
                return true;
            }
        }

        return false;
    }//end hasPublishedDecision()

    /**
     * Revert the bezwaar's status by reading the previous status from
     * the event and writing it back via OpenRegister. The
     * status-transition-engine remains the sole owner of forward
     * transitions; this listener only reverts disallowed jumps.
     *
     * @param array<string, mixed> $bezwaar Current bezwaar payload.
     * @param Event                $event   Event instance.
     *
     * @return void
     */
    private function revertStatus(array $bezwaar, Event $event): void
    {
        $previous = $this->extractPreviousStatus(event: $event);
        if ($previous === '') {
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register      = $this->settingsService->getConfigValue(
            key: 'register'
        );
        $bezwaarSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );
        if ($register === '' || $bezwaarSchema === '') {
            return;
        }

        $bezwaarId = (string) ($bezwaar['id'] ?? ($bezwaar['uuid'] ?? ''));
        if ($bezwaarId === '') {
            return;
        }

        try {
            $objectService->saveObject(
                object: ['status' => $previous],
                register: $register,
                schema: $bezwaarSchema,
                uuid: (string) $bezwaarId
            );
            $this->logger->warning(
                'Procest bezwaar-decision: blocked transition into "'
                .self::PROTECTED_STATUS.'" — no published bezwaarDecision; '
                .'reverted to "'.$previous.'"',
                ['bezwaarId' => $bezwaarId]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest bezwaar-decision: revert failed: '.$e->getMessage()
            );
        }
    }//end revertStatus()

    /**
     * Extract the previous status from an ObjectUpdatedEvent.
     *
     * @param Event $event Event instance.
     *
     * @return string
     */
    private function extractPreviousStatus(Event $event): string
    {
        if (method_exists($event, 'getOldObject') === true) {
            $old = $event->getOldObject();
            $arr = $this->normalise(value: $old);
            if ($arr !== null) {
                return (string) ($arr['status'] ?? '');
            }
        }

        return '';
    }//end extractPreviousStatus()

    /**
     * Whether the object belongs to the `bezwaar` schema.
     *
     * @param array<string, mixed> $object The object payload.
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
            $object['@self']['schema'] ?? ($object['@self']['schemaSlug'] ?? ($object['schema'] ?? ($object['_schemaSlug'] ?? '')))
        );

        return $candidate !== '' && (
            $candidate === $schemaSlug
            || str_ends_with($candidate, '/'.$schemaSlug)
        );
    }//end isBezwaarSchema()

    /**
     * Extract the new object payload from an event.
     *
     * @param Event $event Event instance.
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
     * Normalise a getter return value to an associative array.
     *
     * @param mixed $value The raw value.
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
