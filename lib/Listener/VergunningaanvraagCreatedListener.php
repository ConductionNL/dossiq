<?php

/**
 * Procest Vergunningaanvraag Created Listener
 *
 * Listens for OpenRegister ObjectCreatedEvent on the vergunningaanvraag
 * schema and triggers DsoCaseService to create a Procest zaak from the
 * inbound DSO vergunningaanvraag.
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
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates a Procest zaak whenever OpenRegister stores a new vergunningaanvraag.
 *
 * Filters by schema slug to avoid triggering on other OpenRegister objects.
 * Swallows errors so that a listener failure does not block OpenConnector
 * from completing the write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */
class VergunningaanvraagCreatedListener implements IEventListener
{

    private const VERGUNNINGAANVRAAG_SCHEMA_SLUG = 'vergunningaanvraag';

    /**
     * Constructor.
     *
     * @param DsoCaseService  $dsoCaseService  DSO case service
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly DsoCaseService $dsoCaseService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the ObjectCreatedEvent.
     *
     * Filters to vergunningaanvraag objects only. On match, calls
     * DsoCaseService::createZaakFromVergunningaanvraag().
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        try {
            $object = $this->extractObject(event: $event);
            if ($object === null) {
                return;
            }

            if ($this->isVergunningaanvraagSchema(object: $object) === false) {
                return;
            }

            $objectId = (string) (
                $object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? ''))
            );

            if ($objectId === '') {
                return;
            }

            $this->dsoCaseService->createZaakFromVergunningaanvraag(
                vergunningaanvraagId: $objectId
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'VergunningaanvraagCreatedListener: failed to create zaak: '.$e->getMessage(),
            );
        }//end try
    }//end handle()

    /**
     * Check whether the object belongs to the vergunningaanvraag schema.
     *
     * @param array<string, mixed> $object The OpenRegister object payload
     *
     * @return bool
     */
    private function isVergunningaanvraagSchema(array $object): bool
    {
        $candidate = (string) (
            $object['@self']['schema'] ?? ($object['schema'] ?? '')
        );

        return $candidate !== '' && (
            $candidate === self::VERGUNNINGAANVRAAG_SCHEMA_SLUG
            || str_ends_with(haystack: $candidate, needle: '/'.self::VERGUNNINGAANVRAAG_SCHEMA_SLUG)
        );
    }//end isVergunningaanvraagSchema()

    /**
     * Extract the object payload from the created event.
     *
     * @param Event $event The event
     *
     * @return array<string, mixed>|null
     */
    private function extractObject(Event $event): ?array
    {
        foreach (['getObject', 'getNewObject'] as $method) {
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
     * @param mixed $value Raw value from the event getter
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
