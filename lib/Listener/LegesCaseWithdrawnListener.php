<?php

/**
 * Procest Leges Case Withdrawn Listener
 *
 * When a case transitions to a withdrawn (`ingetrokken`) state and a paid or
 * invoiced leges calculation exists, this listener triggers the refund
 * workflow. It observes OpenRegister ObjectUpdatedEvent on the case schema only
 * and defers to LegesRestitutieService; it owns no refund logic itself.
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\Service\LegesBerekeningService;
use OCA\Procest\Service\LegesRestitutieService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Triggers the leges refund workflow on case withdrawal.
 *
 * @template-implements IEventListener<Event>
 */
class LegesCaseWithdrawnListener implements IEventListener
{
    /**
     * Case status values that count as a withdrawal.
     *
     * @var array<int, string>
     */
    private const WITHDRAWN_STATUSES = ['ingetrokken', 'withdrawn'];

    /**
     * Constructor.
     *
     * @param LegesBerekeningService $berekeningService Calculation read service.
     * @param LegesRestitutieService $restitutieService Refund service.
     * @param LoggerInterface        $logger            Logger.
     */
    public function __construct(
        private readonly LegesBerekeningService $berekeningService,
        private readonly LegesRestitutieService $restitutieService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a case-updated event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        $payload = $this->extractObject(event: $event);
        if ($payload === null || $this->resolveSchemaSlug(payload: $payload) !== 'case') {
            return;
        }

        $caseStatus = strtolower((string) ($payload['caseStatus'] ?? ($payload['phase'] ?? '')));
        if (in_array($caseStatus, self::WITHDRAWN_STATUSES, true) === false) {
            return;
        }

        $caseId = (string) ($payload['id'] ?? ($payload['uuid'] ?? ''));
        if ($caseId === '') {
            return;
        }

        try {
            $berekening = $this->berekeningService->getForCase(caseId: $caseId);
            if ($berekening === null) {
                return;
            }

            $status = (string) ($berekening['status'] ?? '');
            if (in_array($status, ['gefactureerd', 'betaald'], true) === false) {
                return;
            }

            $this->restitutieService->createRestitutie(
                berekeningId: (string) ($berekening['id'] ?? ''),
                reason: 'aanvraag_ingetrokken',
                fase: (string) ($payload['fase'] ?? 'in_behandeling'),
                besluitNemerId: 'system'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: auto-refund on withdrawal failed: '.$e->getMessage());
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
