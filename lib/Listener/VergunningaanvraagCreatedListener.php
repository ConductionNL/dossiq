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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DsoCaseService;
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
	 * @param DsoCaseService $dsoCaseService The DSO case service
	 * @param LoggerInterface $logger The logger
	 * @param BackgroundServiceAccount $serviceAccount Writes when nobody is signed in
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DsoCaseService $dsoCaseService,
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

		$objectId = (string)($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? '')));
		if ($objectId === '') {
			$this->logger->warning(
				'Dossiq DSO listener: event for vergunningaanvraag schema but no object id found',
				['app' => Application::APP_ID]
			);
			return;
		}

		if (in_array((string)($object['status'] ?? ''), self::NOT_READY, true) === true) {
			return;
		}

		if (isset(self::$processedIds[$objectId]) === true) {
			$this->logger->info(
				'Dossiq DSO listener: skipping duplicate event for vergunningaanvraag ' . $objectId,
				['app' => Application::APP_ID]
			);
			return;
		}

		self::$processedIds[$objectId] = true;

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
					'app' => Application::APP_ID,
					'objectId' => $objectId,
				]
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins; nothing was written.
			return;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq DSO listener: failed to create zaak for vergunningaanvraag ' . $objectId . ': ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end handle()

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
