<?php

/**
 * Procest Termijn Case Created Listener.
 *
 * Observes OpenRegister ObjectCreatedEvent on the procest case schema and
 * binds an AWB termijn (TermijnInstance) to the case using the active
 * TermijnDefinitie for the case zaaktype. Defers all work to
 * {@see DeadlineService} (ADR-022). A missing definition is logged at debug
 * level but never blocks case creation.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Procest\Service\ObjectSchemaSlugResolver;
use OCA\Procest\Service\DeadlineService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Binds a TermijnInstance to a freshly-created procest case.
 *
 * @template-implements IEventListener<Event>
 */
class DeadlineCaseCreatedListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param DeadlineService          $deadlineService DeadlineService.
     * @param ObjectSchemaSlugResolver $slugResolver    Schema id-to-slug resolver.
     * @param LoggerInterface          $logger          Logger.
     */
    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly ObjectSchemaSlugResolver $slugResolver,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a case-created event.
     *
     * @param Event $event Event.
     *
     * @return void
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
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

        $caseId   = (string) ($payload['id'] ?? ($payload['uuid'] ?? ''));
        $zaaktype = (string) ($payload['caseType'] ?? ($payload['zaaktype'] ?? ''));
        if ($caseId === '' || $zaaktype === '') {
            return;
        }

        try {
            $this->deadlineService->createTermijnInstance($caseId, $zaaktype);
        } catch (\Throwable $e) {
            // A case without a coupled definition is permissible — debug log only.
            $this->logger->debug(
                'Procest termijn: no automatic binding for case '.$caseId.': '.$e->getMessage()
            );
        }
    }//end handle()

    /**
     * Extract OR object array from an event.
     *
     * @param Event $event Event.
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
     * Resolve the schema slug.
     *
     * The payload carries the schema as an ID (`@self.schema` is
     * `ObjectEntity::$schema`, written as `(string) $schemaId`), and `@self`
     * has no `schemaSlug` key. Reading those keys directly — as this method
     * used to — returned an id or an empty string, so the `!== 'case'` guard in
     * {@see self::handle()} always short-circuited and no AWB TermijnInstance
     * has ever been bound to a case. Resolution goes through the shared
     * {@see ObjectSchemaSlugResolver}.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string
     */
    private function resolveSchemaSlug(array $payload): string
    {
        return $this->slugResolver->resolveFromPayload(payload: $payload);
    }//end resolveSchemaSlug()
}//end class
