<?php

/**
 * Procest AgendaService
 *
 * Service for managing besluitvorming agenda items on cases. An agenda item
 * represents a scheduled discussion of a case in a college/raad meeting and
 * carries: meetingDate, agendaPoint (sequence), discussionStatus, decisionRef.
 *
 * Agenda items are stored on the case object itself as a JSON-encoded
 * `agendaItems[]` array — the case schema's `relations` field is reused, so
 * no new schema is required. This is the minimal storage that the
 * besluitvorming-workflow spec demands; richer state (vote tallies, attendees)
 * lives in separate meeting/voting objects in shillinq/decidesk.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for besluitvorming agenda item management.
 */
class AgendaService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service (resolves OR).
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Add an agenda item to a case.
     *
     * @param string               $caseId The case id.
     * @param array<string, mixed> $item   The agenda-item payload: { meetingDate, agendaPoint?,
     *                                     discussionStatus?, notes? }.
     *
     * @return array<string, mixed> The updated case agenda item list.
     *
     * @throws \RuntimeException When OR is unavailable.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
     */
    public function addToAgenda(string $caseId, array $item): array
    {
        $case = $this->loadCase(caseId: $caseId);

        $items = $this->extractItems(case: $case);

        $item['createdAt'] = $item['createdAt'] ?? date(format: 'c');
        $item['itemId']    = $item['itemId'] ?? uniqid(prefix: 'agenda_', more_entropy: true);
        $items[]           = $item;

        return $this->persistItems(case: $case, items: $items);
    }//end addToAgenda()

    /**
     * Update an agenda item by itemId on a case.
     *
     * @param string               $caseId The case id.
     * @param array<string, mixed> $patch  The patch payload: must include itemId; fields to merge.
     *
     * @return array<string, mixed> The updated case agenda item list.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
     */
    public function updateAgendaItem(string $caseId, array $patch): array
    {
        $case = $this->loadCase(caseId: $caseId);

        $itemId = (string) ($patch['itemId'] ?? '');
        if ($itemId === '') {
            throw new InvalidArgumentException('itemId is required');
        }

        $items = $this->extractItems(case: $case);
        $found = false;
        foreach ($items as $i => $existing) {
            if ((string) ($existing['itemId'] ?? '') === $itemId) {
                $items[$i] = array_merge($existing, $patch, ['itemId' => $itemId]);
                $found     = true;
                break;
            }
        }

        if ($found === false) {
            throw new RuntimeException('Agenda item not found: '.$itemId);
        }

        return $this->persistItems(case: $case, items: $items);
    }//end updateAgendaItem()

    /**
     * Load a case object (raw array).
     *
     * @param string $caseId The case id.
     *
     * @return array<string, mixed> The case object.
     *
     * @throws \RuntimeException When OR is unavailable or case not found.
     */
    private function loadCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        try {
            $obj = $objectService->find(
                id: $caseId,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'AgendaService::loadCase failed',
                ['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
            );
            throw new RuntimeException('Case not found: '.$caseId);
        }

        if ($obj === null) {
            throw new RuntimeException('Case not found: '.$caseId);
        }

        // The OR object may return either a hydrated DTO or array; normalise to array.
        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            return $obj->jsonSerialize();
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return (array) $obj;
    }//end loadCase()

    /**
     * Extract the existing agenda items list from a case array.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return array<int, array<string, mixed>> The agenda items list.
     */
    private function extractItems(array $case): array
    {
        $items = $case['agendaItems'] ?? [];
        if (is_string($items) === true) {
            $decoded = json_decode((string) $items, associative: true);
            $items   = [];
            if (is_array($decoded) === true) {
                $items = $decoded;
            }
        }

        if (is_array($items) === false) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $clean[] = $item;
            }
        }

        return $clean;
    }//end extractItems()

    /**
     * Persist an updated items list to the case.
     *
     * @param array<string, mixed>             $case  The original case object.
     * @param array<int, array<string, mixed>> $items The updated items list.
     *
     * @return array<string, mixed> { caseId, agendaItems }.
     */
    private function persistItems(array $case, array $items): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        $case['agendaItems'] = $items;
        $caseId = (string) ($case['id'] ?? ($case['@self']['id'] ?? ''));

        $objectService->saveObject(
            object: $case,
            register: $register,
            schema: $schema,
        );

        return [
            'caseId'      => $caseId,
            'agendaItems' => $items,
        ];
    }//end persistItems()
}//end class
