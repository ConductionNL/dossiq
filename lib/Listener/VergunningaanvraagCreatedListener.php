<?php

/**
 * Vergunningaanvraag Created Listener
 *
 * Listens for OpenRegister object writes on the configured DSO intake schema
 * and triggers automatic zaak creation. The intake record is integriq's
 * `dso_verzoek`: integriq creates it as `received` and then writes the
 * activity mapping onto it (`mapped`, with `mappedCaseTypes`), so the case is
 * made on the write that carries the mapping, which is an update. A legacy
 * vergunningaanvraag that is created complete is handled on its create.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\DsoCaseServiceInterface;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Listens for DSO intake records and creates a DSO zaak for each.
 *
 * Idempotency: repeated events for the same object ID within a single PHP
 * request are suppressed via a static per-request guard. Across requests,
 * DsoCaseService answers the case an earlier event already made.
 *
 * Account: the writer is whoever is signed in. On a STAM push that is the
 * account integriq's DSO connection acts as. A write that reaches here with
 * nobody signed in (integriq's attachment job under cron) runs as dossiq's
 * background service account, because OpenRegister refuses a write from
 * nobody.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */
class VergunningaanvraagCreatedListener implements IEventListener {

	/**
	 * integriq's states in which the record is not ready to become a case.
	 *
	 * `received` is written before the activity mapping, so it names no case
	 * type yet; `failed` is a verzoek integriq could not translate.
	 */
	private const NOT_READY = ['received', 'failed'];

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
	 * @param DsoCaseServiceInterface $dsoCaseService The DSO case service
	 * @param LoggerInterface $logger The logger
	 * @param BackgroundServiceAccount $serviceAccount Writes when nobody is signed in
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DsoCaseServiceInterface $dsoCaseService,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
	}//end __construct()

	/**
	 * Handle an incoming event.
	 *
	 * Checks whether the event is a create or update of a DSO intake record
	 * that is ready to become a case and, if so, triggers zaak creation via
	 * DsoCaseService.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function handle(Event $event): void {
		$object = $this->normaliseEventObject(event: $event);
		if ($object === null) {
			return;
		}

		$objectId = $this->readyIntakeId(object: $object);
		if ($objectId === '') {
			return;
		}

		if ($this->alreadyHandled(objectId: $objectId) === true) {
			return;
		}

		$this->makeCase(objectId: $objectId, object: $object);
	}//end handle()

	/**
	 * Whether this record was handled before in this process; marks it handled when not.
	 *
	 * @param string $objectId The record's uuid
	 *
	 * @return bool True for a duplicate event, which is logged and skipped
	 */
	private function alreadyHandled(string $objectId): bool {
		if (isset(self::$processedIds[$objectId]) === true) {
			$this->logger->info(
				'Dossiq DSO listener: skipping duplicate event for vergunningaanvraag ' . $objectId,
				['app' => AppIdentity::APP_ID]
			);
			return true;
		}

		self::$processedIds[$objectId] = true;
		return false;
	}//end alreadyHandled()

	/**
	 * Make the case from the record, as the service account when nobody is signed in.
	 *
	 * @param string               $objectId The record's uuid
	 * @param array<string, mixed> $object   The record
	 *
	 * @return void
	 */
	private function makeCase(string $objectId, array $object): void {
		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: fn () => $this->dsoCaseService->createZaakFromVergunningaanvraag(
					permitApplicationId: $objectId,
					permitApplication: $object
				)
			);

			$this->logger->info(
				'Dossiq DSO listener: zaak created for vergunningaanvraag',
				[
					'app' => AppIdentity::APP_ID,
					'objectId' => $objectId,
				]
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins; nothing was written.
			return;
		} catch (\Throwable $e) {
			$this->caseFailed(objectId: $objectId, exception: $e);
		}//end try
	}//end makeCase()

	/**
	 * Log a case that could not be made from the record.
	 *
	 * @param string     $objectId  The record's uuid
	 * @param \Throwable $exception The failure
	 *
	 * @return void
	 */
	private function caseFailed(string $objectId, \Throwable $exception): void {
		$this->logger->error(
			'Dossiq DSO listener: failed to create zaak for vergunningaanvraag ' . $objectId . ': ' . $exception->getMessage(),
			[
				'app' => AppIdentity::APP_ID,
				'objectId' => $objectId,
				'exception' => $exception->getMessage(),
			]
		);
	}//end caseFailed()

	/**
	 * The id of the intake record, when this write is one to make a case from.
	 *
	 * A write on another schema, a record without an id, and integriq's
	 * records before mapping all answer ''.
	 *
	 * @param array<string, mixed> $object The written object
	 *
	 * @return string The record's uuid, or ''
	 */
	private function readyIntakeId(array $object): string {
		if ($this->onIntakeSchema(object: $object) === false) {
			return '';
		}

		$objectId = (string)($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? '')));
		if ($objectId === '') {
			$this->logger->warning(
				'Dossiq DSO listener: event for vergunningaanvraag schema but no object id found',
				['app' => AppIdentity::APP_ID]
			);
			return '';
		}

		if (in_array((string)($object['status'] ?? ''), self::NOT_READY, true) === true) {
			return '';
		}

		return $objectId;
	}//end readyIntakeId()

	/**
	 * Whether the object is on the intake schema this app makes cases from.
	 *
	 * @param array<string, mixed> $object The written object
	 *
	 * @return bool
	 */
	private function onIntakeSchema(array $object): bool {
		$schemaId = $this->resolveSchemaId(object: $object);
		if ($schemaId === '') {
			return false;
		}

		$configuredSchemaId = $this->appConfig->getValueString(
			app: AppIdentity::APP_ID,
			key: 'dso_vergunningaanvraag_schema',
			default: ''
		);

		return $configuredSchemaId !== '' && $schemaId === $configuredSchemaId;
	}//end onIntakeSchema()

	/**
	 * Extract the object payload from a create or update event as an array.
	 *
	 * The event carries an ObjectEntity; this serialises it so the listener
	 * can read the id and `@self` metadata uniformly.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return array<string, mixed>|null The object array, or null when the
	 *                                   event is neither or carries no object
	 */
	private function normaliseEventObject(Event $event): ?array {
		$object = null;
		if ($event instanceof ObjectCreatedEvent) {
			$object = $event->getObject();
		} else if ($event instanceof ObjectUpdatedEvent) {
			$object = $event->getNewObject();
		}

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
