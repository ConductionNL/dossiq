<?php

/**
 * Dossiq PublicationService
 *
 * Service for publishing besluitvorming case decisions. Publishing a besluit
 * means: (a) emitting a publication record with publishedAt + channel, and
 * (b) appending a publishedAt timestamp to the case.
 *
 * The publication record is appended to the case's `publications[]` array;
 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
 * by openconnector wiring (out of scope for the host app build).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Publication\PublicationDateRule;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for besluitvorming publication.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class PublicationService {
	/**
	 * Supported publication channels.
	 */
	public const CHANNELS = ['gemeenteblad', 'website', 'open_raadsinformatie', 'pdc'];

	/**
	 * Integriq's ADR-041 delivery-request event, by FQN string so dossiq
	 * carries no compile-time dependency on the optional integriq app. A
	 * cross-app event class name is a RUNTIME lookup — this app cannot move
	 * it, only follow it (see WorkflowListenerRegistrar for the rename
	 * incident that shaped this rule).
	 *
	 * @var string
	 */
	private const DELIVERY_EVENT = 'OCA\Integriq\Event\DeliveryRequestedEvent';

	/**
	 * What this service delivers, as named on the integriq seam.
	 *
	 * @var string
	 */
	private const DELIVERY_KIND = 'besluit-publication';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param IEventDispatcher $eventDispatcher Dispatches the integriq delivery request (ADR-041).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a besluit on a case.
	 *
	 * Idempotent per (caseId, channel): re-publishing on the same channel
	 * updates the publishedAt timestamp rather than appending duplicates.
	 *
	 * NOTE: As of dossiq-delegate-contract-decision, this method publishes the
	 * already-recorded ZGW Besluit (fed by the decidesk Decision outcome via
	 * BesluitMaterialisationService) rather than authoring a new local besluit.
	 * The publication record is appended to the case's publications[] array;
	 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
	 * by openconnector wiring (out of scope for the host app build).
	 *
	 * @param string $caseId The case id.
	 * @param array<string, mixed> $payload The publish payload: { channel, publishedAt?, notes? }.
	 *
	 * @return array<string, mixed> The publication record + updated case ref.
	 *
	 * @throws \InvalidArgumentException When the requested publication channel is not supported.
	 * @throws \RuntimeException When OR is unavailable or the case can't be loaded.
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function publish(string $caseId, array $payload): array {
		$channel = (string)($payload['channel'] ?? 'website');
		if (in_array($channel, self::CHANNELS, true) === false) {
			throw new InvalidArgumentException('Invalid publication channel: ' . $channel);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$entry = $this->entryFor(caseId: $caseId, channel: $channel);
		$case = $this->loadCase(objectService: $objectService, caseId: $caseId, register: $entry['register'], schema: $entry['schema']);
		$entry += $this->stampOf(payload: $payload);

		// Hand the outbound leg to integriq (ADR-041 seam): dossiq composes
		// WHAT is published; integriq owns HOW it travels. The outcome —
		// including a refusal when integriq is absent or unrouted — is
		// recorded on the publication record so the case shows the delivery
		// status, and it never rolls back the publication itself.
		$entry['delivery'] = $this->requestDelivery(case: $case, entry: $entry);
		$case['publications'] = $this->publicationsWith(case: $case, entry: $entry);
		$case['publishedAt'] = $entry['publishedAt'];

		$objectService->saveObject(object: $case, register: $entry['register'], schema: $entry['schema']);

		return [
			'caseId' => $caseId,
			'channel' => $channel,
			'publishedAt' => $entry['publishedAt'],
			'publications' => $case['publications'],
			'delivery' => $entry['delivery'],
		];
	}//end publish()

	/**
	 * The publication entry's identity and where its case is stored.
	 *
	 * @param string $caseId The case id.
	 * @param string $channel The publication channel.
	 *
	 * @return array{caseId: string, channel: string, register: mixed, schema: mixed} The entry so far.
	 */
	private function entryFor(string $caseId, string $channel): array {
		return [
			'caseId' => $caseId,
			'channel' => $channel,
			'register' => $this->settingsService->getConfigValue('register'),
			'schema' => $this->settingsService->getConfigValue('case_schema'),
		];
	}//end entryFor()

	/**
	 * The publication moment and notes from the publish payload.
	 *
	 * @param array<string, mixed> $payload The publish payload.
	 *
	 * @return array{publishedAt: string, notes: string|null, template: string} The moment (by the
	 *         publication date rule when absent), the notes and the notice template.
	 */
	private function stampOf(array $payload): array {
		$notes = null;
		if (isset($payload['notes']) === true) {
			$notes = (string)$payload['notes'];
		}

		$publishedAt = (string)($payload['publishedAt'] ?? '');
		if ($publishedAt === '') {
			$rule = $this->settingsService->getConfigValue('publication_date_rule', '');
			$publishedAt = (new PublicationDateRule())->publishOn(rule: $rule)->format('c');
		}

		return [
			'publishedAt' => $publishedAt,
			'notes' => $notes,
			'template' => $this->settingsService->getConfigValue('publication_template', ''),
		];
	}//end stampOf()

	/**
	 * The case's publications with this entry upserted by channel and its delivery attached.
	 *
	 * @param array<string, mixed> $case The loaded case data.
	 * @param array<string, mixed> $entry The entry: channel, publishedAt, notes and delivery.
	 *
	 * @return array<int, array<string, mixed>> The updated publications list.
	 */
	private function publicationsWith(array $case, array $entry): array {
		$publications = $this->upsertPublication(
			publications: $this->extractPublications(case: $case),
			channel: $entry['channel'],
			publishedAt: $entry['publishedAt'],
			notes: $entry['notes']
		);

		return $this->attachDelivery(publications: $publications, channel: $entry['channel'], delivery: $entry['delivery']);
	}//end publicationsWith()

	/**
	 * Request cross-app delivery of the publication via integriq's ADR-041
	 * delivery seam.
	 *
	 * Fail-closed: when integriq is absent, does not handle the event, or has
	 * no delivery route configured, the returned record says so explicitly —
	 * a publication is never reported as travelling when nothing carries it.
	 * Terminal status later arrives through integriq's DeliveryConcludedEvent
	 * and is projected by {@see \OCA\Dossiq\Listener\DeliveryConcludedListener}.
	 *
	 * @param array<string, mixed> $case The loaded case data.
	 * @param array<string, mixed> $entry The entry: caseId, channel, register, schema, publishedAt and notes.
	 *
	 * @return array<string, mixed> The delivery record for the publication entry.
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 */
	private function requestDelivery(array $case, array $entry): array {
		$requestedAt = date(format: 'c');
		$where = ['app' => Application::APP_ID, 'caseId' => $entry['caseId'], 'channel' => $entry['channel']];
		if (class_exists('\\' . self::DELIVERY_EVENT) === false) {
			$this->logger->warning('Publication delivery refused: integriq is not installed', $where);
			return ['status' => 'refused', 'reason' => 'integriq_not_installed', 'requestedAt' => $requestedAt];
		}

		$entry['correlationId'] = bin2hex(string: random_bytes(length: 16));
		$event = $this->deliveryEvent(case: $case, entry: $entry);
		$stamp = ['correlationId' => $entry['correlationId'], 'requestedAt' => $requestedAt];

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error('Publication delivery dispatch failed', $where + ['error' => $e->getMessage()]);
			return ['status' => 'refused', 'reason' => 'dispatch_failed'] + $stamp;
		}

		return $this->deliveryOutcome(event: $event, stamp: $stamp);
	}//end requestDelivery()

	/**
	 * The integriq delivery request for one publication entry.
	 *
	 * @param array<string, mixed> $case The loaded case data.
	 * @param array<string, mixed> $entry The entry, with its correlationId.
	 *
	 * @return Event The DeliveryRequestedEvent.
	 */
	private function deliveryEvent(array $case, array $entry): Event {
		$eventClass = '\\' . self::DELIVERY_EVENT;

		return new $eventClass(
			sourceApp: Application::APP_ID,
			subjectRegister: (string)$entry['register'],
			subjectSchema: (string)$entry['schema'],
			subjectId: $entry['caseId'],
			subjectLabel: (string)($case['title'] ?? $entry['caseId']),
			deliveryKind: self::DELIVERY_KIND,
			channel: $entry['channel'],
			payload: [
				'caseId' => $entry['caseId'],
				'channel' => $entry['channel'],
				'publishedAt' => $entry['publishedAt'],
				'notes' => $entry['notes'],
				'template' => ($entry['template'] ?? ''),
				'besluitDocument' => ($case['besluitDocument'] ?? null),
				'identifier' => ($case['identifier'] ?? null),
			],
			correlationId: $entry['correlationId'],
			externalReference: (string)($case['identifier'] ?? ''),
		);
	}//end deliveryEvent()

	/**
	 * The delivery record for a dispatched request.
	 *
	 * @param object $event The dispatched DeliveryRequestedEvent.
	 * @param array{correlationId: string, requestedAt: string} $stamp The correlation id and request moment.
	 *
	 * @return array<string, mixed> Refused when not handled, unrouted when no route matched, else requested.
	 */
	private function deliveryOutcome(object $event, array $stamp): array {
		if ($event->isHandled() === false) {
			return ['status' => 'refused', 'reason' => 'not_handled'] + $stamp;
		}

		if ((int)$event->getMatchedSubscriptions() === 0) {
			// Accepted by integriq, but no delivery route is configured —
			// fail closed rather than implying the publication travelled.
			return ['status' => 'unrouted', 'eventId' => (string)$event->getResultId()] + $stamp;
		}

		return [
			'status' => 'requested',
			'eventId' => (string)$event->getResultId(),
			'correlationId' => $stamp['correlationId'],
			'matchedSubscriptions' => (int)$event->getMatchedSubscriptions(),
			'requestedAt' => $stamp['requestedAt'],
		];
	}//end deliveryOutcome()

	/**
	 * Attach a delivery record to the publication entry for a channel.
	 *
	 * @param array<int, array<string, mixed>> $publications The publications list.
	 * @param string $channel The channel whose entry carries the delivery.
	 * @param array<string, mixed> $delivery The delivery record.
	 *
	 * @return array<int, array<string, mixed>> The updated publications list.
	 */
	private function attachDelivery(array $publications, string $channel, array $delivery): array {
		foreach ($publications as $i => $pub) {
			if ((string)($pub['channel'] ?? '') === $channel) {
				$publications[$i]['delivery'] = $delivery;
				break;
			}
		}

		return $publications;
	}//end attachDelivery()

	/**
	 * Load a case from OpenRegister and normalise it to its array form.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $caseId The case id.
	 * @param mixed $register The configured register id.
	 * @param mixed $schema The configured case schema id.
	 *
	 * @return array<string, mixed> The case data.
	 *
	 * @throws \RuntimeException When the case cannot be loaded or does not exist.
	 */
	private function loadCase(object $objectService, string $caseId, mixed $register, mixed $schema): array {
		try {
			$obj = $objectService->find(id: $caseId, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (Throwable $e) {
			$this->logger->error(
				'PublicationService::publish find failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		if ($obj === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		// An array casts to itself, so the cast doubles as the plain-object fallback.
		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end loadCase()

	/**
	 * Upsert a publication record by channel — an existing record for the same
	 * channel has its timestamp and notes replaced rather than being duplicated.
	 *
	 * @param array<int, array<string, mixed>> $publications The existing publications list.
	 * @param string $channel The publication channel.
	 * @param string $publishedAt The publication timestamp.
	 * @param string|null $notes Optional publication notes.
	 *
	 * @return array<int, array<string, mixed>> The updated publications list.
	 */
	private function upsertPublication(array $publications, string $channel, string $publishedAt, ?string $notes): array {
		$upserted = false;
		foreach ($publications as $i => $pub) {
			if ((string)($pub['channel'] ?? '') === $channel) {
				$publications[$i] = [
					'channel' => $channel,
					'publishedAt' => $publishedAt,
					'notes' => $notes,
				];
				$upserted = true;
				break;
			}
		}

		if ($upserted === false) {
			$publications[] = [
				'channel' => $channel,
				'publishedAt' => $publishedAt,
				'notes' => $notes,
			];
		}

		return $publications;
	}//end upsertPublication()

	/**
	 * Pull the existing publications list from a case.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return array<int, array<string, mixed>> The publications list.
	 */
	private function extractPublications(array $case): array {
		$pubs = $case['publications'] ?? [];
		if (is_string($pubs) === true) {
			$decoded = json_decode((string)$pubs, associative: true);
			$pubs = [];
			if (is_array($decoded) === true) {
				$pubs = $decoded;
			}
		}

		if (is_array($pubs) === false) {
			return [];
		}

		$clean = [];
		foreach ($pubs as $pub) {
			if (is_array($pub) === true) {
				$clean[] = $pub;
			}
		}

		return $clean;
	}//end extractPublications()
}//end class
