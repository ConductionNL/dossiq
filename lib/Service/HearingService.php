<?php

/**
 * Dossiq Hearing Service
 *
 * Service for managing hoorgesprekken (hearings) linked to complaints.
 * Handles scheduling, calendar events via OCP\Calendar\IManager,
 * and Talk room creation via OCP\Talk\IBroker for videogesprek hearings.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Conversation\TalkConversationBroker;
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\Support\ConfiguredSchemaRows;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for hearing (hoorgesprek) management within the complaint workflow.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 */
class HearingService {

	use SearchesObjects;

	/**
	 * Reads a configured schema, or answers an empty list when it cannot.
	 *
	 * @var ConfiguredSchemaRows
	 */
	private readonly ConfiguredSchemaRows $rows;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 * @param HearingCalendarService $calendarService Writes the hearing onto participants' calendars
	 * @param TalkConversationBroker $talkBroker Opens the Talk room a video hearing runs in
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly HearingCalendarService $calendarService,
		private readonly TalkConversationBroker $talkBroker,
	) {
		$this->rows = new ConfiguredSchemaRows(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Schedule a new hearing for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Hearing data (datum, locatie, type, deelnemers)
	 *
	 * @return array<string, mixed> Created hearing
	 *
	 * @throws \RuntimeException If required fields missing or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function scheduleHearing(string $complaintId, array $data): array {
		$data = array_diff_key($data, CallerIdentity::KEYS);
		$this->requireHearingFields(data: $data);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('hearing_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Hearing schema not configured');
		}

		$data['complaint'] = $complaintId;

		$data = $this->withTalkRoom(complaintId: $complaintId, data: $data);

		// Put the hearing on every participant's calendar before it is stored,
		// so the id the schema reserves is written with the hearing itself.
		$data['calendarEventId'] = $this->calendarService->createEvent(data: $data);

		$hearing = $objectService->saveObject(object: $data, register: $register, schema: $schema);

		$this->logger->info(
			'Hearing scheduled for complaint ' . $complaintId . ' on ' . $data['date'],
			['app' => AppIdentity::APP_ID],
		);

		return $this->hearingAnswer(hearing: $hearing, data: $data);
	}//end scheduleHearing()

	/**
	 * Refuse a hearing without a date or a type.
	 *
	 * @param array<string, mixed> $data Hearing data (datum, locatie, type, deelnemers)
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If required fields missing
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function requireHearingFields(array $data): void {
		if (empty($data['date']) === true) {
			throw new RuntimeException('Hearing datum is required');
		}

		if (empty($data['type']) === true) {
			throw new RuntimeException('Hearing type is required');
		}
	}//end requireHearingFields()

	/**
	 * The hearing data with a Talk room for a video hearing.
	 *
	 * @param string               $complaintId Complaint UUID
	 * @param array<string, mixed> $data        Hearing data
	 *
	 * @return array<string, mixed> The hearing data.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function withTalkRoom(string $complaintId, array $data): array {
		// Create Talk room for video hearings.
		if ($data['type'] === 'videogesprek') {
			$talkUrl = $this->createTalkRoom(complaintId: $complaintId);
			$data['talkRoomUrl'] = $talkUrl;
			if (empty($data['location']) === true) {
				$data['location'] = $talkUrl;
			}
		}

		return $data;
	}//end withTalkRoom()

	/**
	 * The created hearing as an array.
	 *
	 * @param mixed                $hearing The saveObject() result.
	 * @param array<string, mixed> $data    The hearing data that was saved.
	 *
	 * @return array<string, mixed> Created hearing
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function hearingAnswer(mixed $hearing, array $data): array {
		if (is_array($hearing) === true) {
			return $hearing;
		}

		return array_merge($data, ['id' => $hearing->getUuid()]);
	}//end hearingAnswer()

	/**
	 * Get a hearing by ID.
	 *
	 * @param string $id Hearing UUID
	 *
	 * @return array<string, mixed>|null Hearing data or null
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function getHearing(string $id): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('hearing_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id
		);
	}//end getHearing()

	/**
	 * List hearings for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 *
	 * @return array<int, array<string, mixed>> List of hearings
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function getHearingsForComplaint(string $complaintId): array {
		return $this->rows->search(
			schemaKey: 'hearing_schema',
			filters: ['complaint' => $complaintId]
		);
	}//end getHearingsForComplaint()

	/**
	 * Record the outcome of a completed hearing.
	 *
	 * @param string $id Hearing UUID
	 * @param array<string, mixed> $outcome Outcome data (verslag, conclusie, aanwezigen, datumAfgerond)
	 *
	 * @return array<string, mixed> Updated hearing
	 *
	 * @throws \RuntimeException If verslag is missing or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function recordOutcome(string $id, array $outcome): array {
		if (empty($outcome['minutes']) === true) {
			throw new RuntimeException('Verslag is required to record a hearing outcome');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('hearing_schema');

		$updateData = [
			'minutes' => $outcome['minutes'],
			'conclusion' => $outcome['conclusion'] ?? '',
			'attendees' => $outcome['attendees'] ?? [],
			'dateCompleted' => $outcome['dateCompleted'] ?? date('Y-m-d'),
		];

		$result = $this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$id, changes: $updateData);

		$this->logger->info(
			'Hearing outcome recorded for hearing ' . $id,
			['app' => AppIdentity::APP_ID],
		);

		if (is_array($result) === true) {
			return $result;
		}

		return array_merge($updateData, ['id' => $id]);
	}//end recordOutcome()

	/**
	 * Create a Nextcloud Talk room for a video hearing.
	 *
	 * The hoorzitting is now one configured use of the case-level conversation
	 * mechanism rather than the only place in dossiq that can open a room:
	 * the broker lookup moved to {@see TalkConversationBroker}, which every
	 * conversation on any case goes through. Behaviour here is unchanged, an
	 * instance without Talk still degrades to an empty string.
	 *
	 * @param string $complaintId Complaint UUID (used as room name)
	 *
	 * @return string Talk room URL or empty string if Talk not available
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function createTalkRoom(string $complaintId): string {
		$roomName = 'Hoorgesprek klacht ' . $complaintId;

		$this->logger->debug(
			'Creating Talk room for complaint hearing',
			['complaintId' => $complaintId, 'roomName' => $roomName, 'app' => AppIdentity::APP_ID],
		);

		$room = $this->talkBroker->createRoom(name: $roomName);
		if ($room === null) {
			return '';
		}

		return $room['url'];
	}//end createTalkRoom()

}//end class
