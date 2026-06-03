<?php

/**
 * Procest Leges Case Created Listener
 *
 * On creation of a case whose case type is coupled to a leges tariff, this
 * listener triggers an automatic leges calculation. It observes OpenRegister
 * ObjectCreatedEvent on the case schema only and defers all work to
 * LegesCaseCalculationService; it owns no calculation logic itself (ADR-022).
 *
 * @category Listener
 * @package  OCA\Procest\Listener
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Procest\Service\LegesCaseCalculationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Triggers an automatic leges calculation when a coupled case is created.
 *
 * @template-implements IEventListener<Event>
 */
class LegesCaseCreatedListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param LegesCaseCalculationService $calculationService Calculation orchestrator.
     * @param LoggerInterface             $logger             Logger.
     */
    public function __construct(
        private readonly LegesCaseCalculationService $calculationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a case-created event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false) {
            return;
        }

        $payload = $this->extractObject(event: $event);
        if ($payload === null) {
            return;
        }

        if ($this->resolveSchemaSlug(payload: $payload) !== 'case') {
            return;
        }

        $caseId = (string) ($payload['id'] ?? ($payload['uuid'] ?? ''));
        if ($caseId === '') {
            return;
        }

        try {
            $this->calculationService->calculateForCase(caseId: $caseId, calculatedBy: 'system');
        } catch (\Throwable $e) {
            // A case without a coupled tariff is normal — log at debug, never block creation.
            $this->logger->debug(
                'Procest leges: no automatic calculation for case '.$caseId.': '.$e->getMessage()
            );
        }
    }//end handle()

    /**
     * Extract the OR object array from an event.
     *
     * @param Event $event The event.
     *
     * @return array<string, mixed>|null
     */
    private function extractObject(Event $event): ?array
    {
        if (method_exists($event, 'getObject') === false) {
            return null;
        }

        $object = $event->getObject();
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end extractObject()

    /**
     * Resolve the schema slug for an OR object payload.
     *
     * @param array<string, mixed> $payload The object payload.
     *
     * @return string
     */
    private function resolveSchemaSlug(array $payload): string
    {
        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            $self = $payload['@self'];
            if (isset($self['schemaSlug']) === true) {
                return (string) $self['schemaSlug'];
            }

            if (isset($self['schema']) === true && is_string($self['schema']) === true) {
                return $self['schema'];
            }
        }

        if (isset($payload['_schemaSlug']) === true) {
            return (string) $payload['_schemaSlug'];
        }

        if (isset($payload['schemaSlug']) === true) {
            return (string) $payload['schemaSlug'];
        }

        return '';
    }//end resolveSchemaSlug()
}//end class
