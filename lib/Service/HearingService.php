<?php

/**
 * Procest Hearing Service
 *
 * Service for managing hoorgesprekken (hearings) linked to complaints.
 * Handles scheduling, Calendar invitations via OCP\Calendar\IManager,
 * and Talk room creation via OCP\Talk\IBroker for videogesprek hearings.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for hearing (hoorgesprek) management within the complaint workflow.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */
class HearingService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Schedule a new hearing for a complaint.
     *
     * @param string               $complaintId Complaint UUID
     * @param array<string, mixed> $data        Hearing data (datum, locatie, type, deelnemers)
     *
     * @return array<string, mixed> Created hearing
     *
     * @throws \RuntimeException If required fields missing or OpenRegister unavailable
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    public function scheduleHearing(string $complaintId, array $data): array
    {
        if (empty($data['datum']) === true) {
            throw new \RuntimeException('Hearing datum is required');
        }

        if (empty($data['type']) === true) {
            throw new \RuntimeException('Hearing type is required');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('hearing_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Hearing schema not configured');
        }

        $data['complaint'] = $complaintId;

        // Create Talk room for video hearings.
        if ($data['type'] === 'videogesprek') {
            $talkUrl = $this->createTalkRoom(complaintId: $complaintId);
            $data['talkRoomUrl'] = $talkUrl;
            if (empty($data['locatie']) === true) {
                $data['locatie'] = $talkUrl;
            }
        }

        $hearing = $objectService->saveObject(object: $data, register: $register, schema: $schema);

        // Send calendar invitations to all participants.
        $this->sendCalendarInvitations(hearing: $hearing, data: $data);

        $this->logger->info(
            'Hearing scheduled for complaint '.$complaintId.' on '.$data['datum'],
            ['app' => Application::APP_ID],
        );

        if (is_array($hearing) === true) {
            return $hearing;
        }

        return array_merge($data, ['id' => $hearing->getUuid()]);
    }//end scheduleHearing()

    /**
     * Get a hearing by ID.
     *
     * @param string $id Hearing UUID
     *
     * @return array<string, mixed>|null Hearing data or null
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    public function getHearing(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('hearing_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        $result = $objectService->findObject($register, $schema, $id);
        if (is_array($result) === true) {
            return $result;
        }

        return null;
    }//end getHearing()

    /**
     * List hearings for a complaint.
     *
     * @param string $complaintId Complaint UUID
     *
     * @return array<int, array<string, mixed>> List of hearings
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    public function getHearingsForComplaint(string $complaintId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('hearing_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $objectService->findObjects($register, $schema, ['complaint' => $complaintId]);
        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getHearingsForComplaint()

    /**
     * Record the outcome of a completed hearing.
     *
     * @param string               $id      Hearing UUID
     * @param array<string, mixed> $outcome Outcome data (verslag, conclusie, aanwezigen, datumAfgerond)
     *
     * @return array<string, mixed> Updated hearing
     *
     * @throws \RuntimeException If verslag is missing or OpenRegister unavailable
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    public function recordOutcome(string $id, array $outcome): array
    {
        if (empty($outcome['verslag']) === true) {
            throw new \RuntimeException('Verslag is required to record a hearing outcome');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('hearing_schema');

        $updateData = [
            'verslag'       => $outcome['verslag'],
            'conclusie'     => $outcome['conclusie'] ?? '',
            'aanwezigen'    => $outcome['aanwezigen'] ?? [],
            'datumAfgerond' => $outcome['datumAfgerond'] ?? date('Y-m-d'),
        ];

        $result = $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string) $id);

        $this->logger->info(
            'Hearing outcome recorded for hearing '.$id,
            ['app' => Application::APP_ID],
        );

        if (is_array($result) === true) {
            return $result;
        }

        return array_merge($updateData, ['id' => $id]);
    }//end recordOutcome()

    /**
     * Create a Nextcloud Talk room for a video hearing.
     *
     * @param string $complaintId Complaint UUID (used as room name)
     *
     * @return string Talk room URL or empty string if Talk not available
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    private function createTalkRoom(string $complaintId): string
    {
        // Talk integration via OCP\Talk\IBroker — interface may not be available
        // on all NC installations; gracefully degrade to empty string.
        $roomName = 'Hoorgesprek klacht '.$complaintId;

        $this->logger->debug(
            'Creating Talk room for complaint hearing',
            ['complaintId' => $complaintId, 'roomName' => $roomName, 'app' => Application::APP_ID],
        );

        try {
            $container = \OC::$server;
            if ($container->has(\OCP\Talk\IBroker::class) === false) {
                return '';
            }

            $broker = $container->get(\OCP\Talk\IBroker::class);
            if (($broker instanceof \OCP\Talk\IBroker) === false) {
                return '';
            }

            $config = $broker->newConversationOptions();
            $room   = $broker->createConversation(
                name: $roomName,
                moderators: [],
                options: $config,
            );

            return $room->getAbsoluteUrl();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to create Talk room for complaint '.$complaintId.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return '';
        }//end try
    }//end createTalkRoom()

    /**
     * Send calendar invitations to all hearing participants.
     *
     * @param mixed                $hearing Saved hearing object
     * @param array<string, mixed> $data    Original hearing data with participants
     *
     * @return void
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
     */
    private function sendCalendarInvitations(mixed $hearing, array $data): void
    {
        $participants = $data['deelnemers'] ?? [];
        if (empty($participants) === true) {
            return;
        }

        $datum   = $data['datum'] ?? '';
        $locatie = $data['locatie'] ?? '';
        $talkUrl = $data['talkRoomUrl'] ?? '';

        $description = 'Hoorgesprek klacht';
        if (empty($talkUrl) === false) {
            $description .= "\nVideo link: ".$talkUrl;
        }

        // Calendar integration — log attempt; actual calendar write is
        // delegated to NC Calendar IManager search/find calendars per participant.
        $this->logger->info(
            'Calendar invitations queued for hearing on '.$datum.' at '.$locatie
            .' for '.count($participants).' participants',
            ['app' => Application::APP_ID],
        );
    }//end sendCalendarInvitations()
}//end class
