<?php

/**
 * Procest Vergunningaanvraag Created Listener
 *
 * Listens for OpenRegister ObjectCreatedEvent events filtered to the
 * vergunningaanvraag schema and delegates to DsoCaseService to create
 * a corresponding Procest zaak.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Creates a Procest zaak whenever a vergunningaanvraag is written to
 * the DSO register by OpenConnector.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */
class VergunningaanvraagCreatedListener implements IEventListener
{
    /**
     * Schema slugs that trigger zaak creation.
     *
     * @var array<string>
     */
    private const VERGUNNINGAANVRAAG_SLUGS = ['vergunningaanvraag'];

    /**
     * Constructor.
     *
     * @param DsoCaseService  $dsoCaseService  The DSO case service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly DsoCaseService $dsoCaseService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectCreatedEvent for the vergunningaanvraag schema.
     *
     * Extracts the object, checks whether it belongs to the vergunningaanvraag
     * schema, and calls DsoCaseService::createZaakFromVergunningaanvraag().
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
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

        $schemaSlug = $this->resolveSchemaSlug(payload: $payload);
        if (in_array($schemaSlug, self::VERGUNNINGAANVRAAG_SLUGS, true) === false) {
            return;
        }

        $objectId = (string) ($payload['uuid'] ?? ($payload['id'] ?? ''));
        if ($objectId === '') {
            $this->logger->warning(
                'VergunningaanvraagCreatedListener: object has no ID, skipping',
                ['app' => Application::APP_ID],
            );
            return;
        }

        try {
            $zaak = $this->dsoCaseService->createZaakFromVergunningaanvraag(
                vergunningaanvraagId: $objectId,
            );

            $zaakId = (string) ($zaak['uuid'] ?? ($zaak['id'] ?? ''));
            $this->logger->info(
                'VergunningaanvraagCreatedListener: zaak created',
                [
                    'app'                   => Application::APP_ID,
                    'vergunningaanvraagRef' => $objectId,
                    'zaakId'                => $zaakId,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'VergunningaanvraagCreatedListener: failed to create zaak',
                [
                    'app'                   => Application::APP_ID,
                    'vergunningaanvraagRef' => $objectId,
                    'error'                 => $e->getMessage(),
                ],
            );
        }//end try
    }//end handle()

    /**
     * Extract the OR object array from an ObjectCreatedEvent.
     *
     * @param Event $event Event instance
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
     * Resolve the schema slug from the OR object payload.
     *
     * @param array<string, mixed> $payload Object payload
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
