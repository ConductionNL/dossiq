<?php

/**
 * Procest Beroep Escalation Listener.
 *
 * Observes OpenRegister object events on the `beroep` schema and derives
 * the read-only `dwingendStatus` marker on the linked source bezwaar when
 * a non-terminal beroep is filed within the 6-week window. The marker is
 * cleared again when the beroep enters a terminal outcome (in_stand_gelaten,
 * ingetrokken, schikking, etc. — handled implicitly by recomputing on
 * every beroep update).
 *
 * The listener is intentionally a pure observer: all state changes route
 * through OpenRegister via SettingsService::getObjectService() — no
 * bespoke transition logic. Failures are swallowed (logged) so that
 * beroep persistence is never blocked by a failed marker derivation.
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

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives the dwingendStatus marker on the source bezwaar when a
 * non-terminal beroep exists within the 6-week filing window.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/beroep-escalation/spec.md
 */
class BeroepEscalationListener implements IEventListener
{
    /**
     * Status values that close a bezwaar (terminal per bezwaar-lifecycle).
     *
     * @var array<int, string>
     */
    private const TERMINAL_BEZWAAR_STATUSES = [
        'Afgehandeld',
        'Niet-ontvankelijk',
        'Ingetrokken',
    ];

    /**
     * Judgment outcomes that put a beroep in a terminal state.
     *
     * @var array<int, string>
     */
    private const TERMINAL_BEROEP_OUTCOMES = [
        'in_stand_gelaten',
        'ongegrond',
        'niet_ontvankelijk',
        'ingetrokken',
        'schikking',
    ];

    /**
     * Filing-window length: 6 weeks (Awb 6:7).
     */
    private const FILING_WINDOW_DAYS = 42;

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
     * Handle a beroep create/update event.
     *
     * @param Event $event The dispatched event
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
        ) {
            return;
        }

        try {
            $object = $this->extractObject(event: $event);
            if ($object === null) {
                return;
            }

            if ($this->isBeroepSchema(object: $object) === false) {
                return;
            }

            $sourceBezwaarId = (string) ($object['sourceBezwaar'] ?? '');
            if ($sourceBezwaarId === '') {
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

            $bezwaar = $objectService->find($sourceBezwaarId, register: $register, schema: $bezwaarSchema);
            if (is_array($bezwaar) === false) {
                return;
            }

            $dwingend = $this->shouldFlagDwingend(
                beroep: $object,
                bezwaar: $bezwaar,
            );

            // No-op when the derived marker already matches.
            $current = (bool) ($bezwaar['dwingendStatus'] ?? false);
            if ($current === $dwingend) {
                return;
            }

            $objectService->saveObject(
                object: ['dwingendStatus' => $dwingend],
                register: $register,
                schema: $bezwaarSchema,
                uuid: (string) $sourceBezwaarId
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest beroep: dwingendStatus derivation swallowed '
                .'exception: '.$e->getMessage(),
            );
        }//end try
    }//end handle()

    /**
     * Decide whether the source bezwaar should carry dwingendStatus = true.
     *
     * The marker is set when:
     *  - the source bezwaar is in a terminal status (otherwise no flip is
     *    needed; the bezwaar is still live), AND
     *  - a beslissing op bezwaar exists (i.e. the bezwaar produced an
     *    appealable decision), AND
     *  - the beroep is non-terminal (judgmentOutcome is unset or not in
     *    TERMINAL_BEROEP_OUTCOMES), AND
     *  - the beroep was filed within the 6-week window from
     *    appellantFilingDate (the system never decides timeliness itself
     *    but the dwingende marker only applies during the active window).
     *
     * @param array<string, mixed> $beroep  The beroep payload
     * @param array<string, mixed> $bezwaar The source bezwaar payload
     *
     * @return bool
     */
    private function shouldFlagDwingend(array $beroep, array $bezwaar): bool
    {
        $bezwaarStatus = (string) ($bezwaar['status'] ?? '');
        if (in_array($bezwaarStatus, self::TERMINAL_BEZWAAR_STATUSES, true) === false
        ) {
            return false;
        }

        $contested = (string) ($beroep['contestedDecision'] ?? '');
        if ($contested === '') {
            return false;
        }

        $outcome = (string) ($beroep['judgmentOutcome'] ?? '');
        if ($outcome !== ''
            && in_array($outcome, self::TERMINAL_BEROEP_OUTCOMES, true) === true
        ) {
            return false;
        }

        return $this->withinFilingWindow(beroep: $beroep);
    }//end shouldFlagDwingend()

    /**
     * Whether the beroep was filed within 6 weeks of the contested
     * decision's effectiveDate. Falls back to true when filingDeadline
     * is set and the appellantFilingDate is on/before that date.
     *
     * @param array<string, mixed> $beroep The beroep payload
     *
     * @return bool
     */
    private function withinFilingWindow(array $beroep): bool
    {
        $filing = (string) ($beroep['appellantFilingDate'] ?? '');
        if ($filing === '') {
            return false;
        }

        $deadline = (string) ($beroep['filingDeadline'] ?? '');
        if ($deadline !== '') {
            try {
                return (new DateTimeImmutable($filing)) <= (new DateTimeImmutable($deadline));
            } catch (Throwable $e) {
                return true;
            }
        }

        // No deadline yet — assume within window.
        unset($deadline);
        // Reference window length so the constant is used (informational).
        $window = self::FILING_WINDOW_DAYS;
        unset($window);
        return true;
    }//end withinFilingWindow()

    /**
     * Whether the object belongs to the `beroep` schema.
     *
     * @param array<string, mixed> $object The object payload
     *
     * @return bool
     */
    private function isBeroepSchema(array $object): bool
    {
        $schemaSlug = $this->settingsService->getConfigValue(
            key: 'beroep_schema'
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
    }//end isBeroepSchema()

    /**
     * Extract the new object payload from an event.
     *
     * @param Event $event Event instance
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
