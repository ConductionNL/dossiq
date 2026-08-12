<?php

/**
 * Vergunningaanvraag Created Listener
 *
 * Listens for ObjectCreatedEvent from OpenRegister and triggers
 * automatic zaak creation for new vergunningaanvraag objects matching
 * the configured schema. This wires the DSO intake flow into the
 * Procest case management engine without coupling the object storage
 * layer to the domain logic.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoCaseService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Listens for new OpenRegister objects and creates a DSO zaak when the
 * schema matches the configured vergunningaanvraag schema.
 *
 * Idempotency: duplicate ObjectCreatedEvents for the same object ID within
 * a single PHP request are suppressed via a static per-request guard.
 * Cross-request uniqueness is the responsibility of the zaak-creation service.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 */
class VergunningaanvraagCreatedListener implements IEventListener {

	/**
	 * Per-request guard tracking already-processed object IDs to prevent duplicate zaak creation.
	 *
	 * @var array<string,bool>
	 */
	private static array $processedIds = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param DsoCaseService $dsoCaseService The DSO case service
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DsoCaseService $dsoCaseService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an incoming event.
	 *
	 * Checks whether the event is an ObjectCreatedEvent for a vergunningaanvraag
	 * object and, if so, triggers zaak creation via DsoCaseService.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$object = $this->normaliseEventObject(event: $event);
		if ($object === null) {
			return;
		}

		$schemaId = $this->resolveSchemaId(object: $object);
		if ($schemaId === '') {
			return;
		}

		$configuredSchemaId = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_vergunningaanvraag_schema',
			default: ''
		);

		if ($configuredSchemaId === '' || $schemaId !== $configuredSchemaId) {
			return;
		}

		$objectId = (string)($object['id'] ?? ($object['uuid'] ?? ''));
		if ($objectId === '') {
			$this->logger->warning(
				'Procest DSO listener: ObjectCreatedEvent for vergunningaanvraag schema but no object id found',
				['app' => Application::APP_ID]
			);
			return;
		}

		if (isset(self::$processedIds[$objectId]) === true) {
			$this->logger->info(
				'Procest DSO listener: skipping duplicate ObjectCreatedEvent for vergunningaanvraag ' . $objectId,
				['app' => Application::APP_ID]
			);
			return;
		}

		self::$processedIds[$objectId] = true;

		try {
			$this->dsoCaseService->createZaakFromVergunningaanvraag(vergunningaanvraagId: $objectId);
			$this->logger->info(
				'Procest DSO listener: zaak created for vergunningaanvraag',
				[
					'app' => Application::APP_ID,
					'objectId' => $objectId,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest DSO listener: failed to create zaak for vergunningaanvraag ' . $objectId . ': ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end handle()

	/**
	 * Normalise the event payload to the array shape the schema/id resolution
	 * expects.
	 *
	 * OpenRegister's ObjectCreatedEvent::getObject() returns an ObjectEntity
	 * (JsonSerializable). A bare array is also accepted for resilience against
	 * alternate event shapes / test doubles.
	 *
	 * @param ObjectCreatedEvent $event The dispatched creation event
	 *
	 * @return array<string, mixed>|null The object array, or null when the
	 *                                   payload is not array-shaped
	 */
	private function normaliseEventObject(ObjectCreatedEvent $event): ?array {
		$object = $event->getObject();
		if ($object instanceof \JsonSerializable === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return null;
		}

		return $object;
	}//end normaliseEventObject()

	/**
	 * Resolve the schema identifier from an OR object payload.
	 *
	 * Supports the various shapes that OpenRegister uses to embed the schema
	 * reference on a serialised object (numeric id in @self, slug string).
	 *
	 * @param array<string,mixed> $object The OR object array
	 *
	 * @return string The schema id/slug, or empty string when not determinable
	 */
	private function resolveSchemaId(array $object): string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['schema']) === true) {
				return (string)$self['schema'];
			}
		}

		if (isset($object['schema']) === true) {
			return (string)$object['schema'];
		}

		return '';
	}//end resolveSchemaId()
}//end class
