<?php

/**
 * Procest Agenda Service
 *
 * Compiles besluitvorming cases that are "Gereed voor agendering" into a
 * vergadering agenda per vergadergremium. Supports classifying each item as a
 * hamerstuk or bespreekstuk, ordering items, transitioning cases to
 * "Geagendeerd" with an agendanummer, and producing an ordered agenda document
 * (hamerstukken first, then bespreekstukken) via Docudesk.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Agenda compiler for besluitvorming vergaderingen.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates ObjectService + Docudesk.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
 */
class AgendaService
{
    /**
     * Item classification: consent agenda.
     */
    public const BEHANDELING_HAMERSTUK = 'hamerstuk';

    /**
     * Item classification: discussion item.
     */
    public const BEHANDELING_BESPREEKSTUK = 'bespreekstuk';

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Bridge to OpenRegister + config.
     * @param ContainerInterface $container       DI container (for optional Docudesk).
     * @param LoggerInterface    $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List cases ready for agendering, scoped to a vergadergremium (caseType).
     *
     * @param string $vergadergremium The caseType title (e.g. "Raadsbesluit").
     *
     * @return array<int, array<string, mixed>> The ready cases.
     *
     * @throws RuntimeException When OpenRegister/config is unavailable.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    public function getReadyItems(string $vergadergremium): array
    {
        [$objectService, $register, $caseSchema] = $this->bootstrapCase();

        $statusId = $this->resolveStatusForGremium(
            objectService: $objectService,
            register: $register,
            gremium: $vergadergremium,
            statusName: 'Gereed voor agendering',
        );

        $filters = ['register' => $register, 'schema' => $caseSchema];
        if ($statusId !== '') {
            $filters['status'] = $statusId;
        }

        $caseTypeId = $this->resolveCaseTypeId(objectService: $objectService, register: $register, title: $vergadergremium);
        if ($caseTypeId !== '') {
            $filters['caseType'] = $caseTypeId;
        }

        $results = $objectService->findAll(['filters' => $filters, 'limit' => 200]);
        if (is_array($results) === true && isset($results['results']) === true) {
            $results = $results['results'];
        }

        $items = [];
        foreach ((array) $results as $row) {
            $items[] = $this->toArray(value: $row);
        }

        return $items;
    }//end getReadyItems()

    /**
     * Add a case to an agenda by setting its classification and order.
     *
     * @param string $caseId         The case UUID.
     * @param string $classification hamerstuk|bespreekstuk.
     * @param int    $order          The agenda order position.
     *
     * @return array<string, mixed> The updated caseProperty values.
     *
     * @throws RuntimeException When the classification is invalid or storage fails.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    public function addItem(string $caseId, string $classification, int $order): array
    {
        if (in_array($classification, [self::BEHANDELING_HAMERSTUK, self::BEHANDELING_BESPREEKSTUK], true) === false) {
            throw new RuntimeException('Ongeldige behandeling: '.$classification);
        }

        [$objectService, $register] = $this->bootstrapProperty();

        $this->upsertCaseProperty(
            objectService: $objectService,
            register: $register,
            caseId: $caseId,
            name: 'behandeling',
            value: $classification,
        );
        $this->upsertCaseProperty(
            objectService: $objectService,
            register: $register,
            caseId: $caseId,
            name: 'agendaVolgorde',
            value: (string) $order,
        );

        return ['case' => $caseId, 'behandeling' => $classification, 'order' => $order];
    }//end addItem()

    /**
     * Confirm an agenda: transition cases to "Geagendeerd" and assign agendanummers.
     *
     * @param array<int, string> $caseIds     The ordered case UUIDs to agendize.
     * @param string             $meetingDate The meeting date (ISO yyyy-mm-dd).
     *
     * @return array<string, mixed> A summary of confirmed cases and their agendanummers.
     *
     * @throws RuntimeException When OpenRegister/config is unavailable.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    public function confirmAgenda(array $caseIds, string $meetingDate): array
    {
        [$objectService, $register, $caseSchema] = $this->bootstrapCase();
        $propertyRegister = $register;

        $confirmed = [];
        $index     = 1;
        foreach ($caseIds as $caseId) {
            $caseId = (string) $caseId;
            if ($caseId === '') {
                continue;
            }

            $agendanummer = sprintf('%d.%d', 1, $index);
            $index++;

            $case     = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
            $statusId = $this->resolveStatusForCase(
                objectService: $objectService,
                register: $register,
                case: $case,
                statusName: 'Geagendeerd',
            );

            try {
                $update = ['vergaderdatum' => $meetingDate];
                if ($statusId !== '') {
                    $update['status'] = $statusId;
                }

                $objectService->saveObject($register, $caseSchema, $update, $caseId);
                $this->upsertCaseProperty(
                    objectService: $objectService,
                    register: $propertyRegister,
                    caseId: $caseId,
                    name: 'agendanummer',
                    value: $agendanummer,
                );
                $this->notifyAgendered(case: $case);

                $confirmed[] = ['case' => $caseId, 'agendanummer' => $agendanummer];
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: could not agendize case',
                    ['case' => $caseId, 'exception' => $e->getMessage()],
                );
            }//end try
        }//end foreach

        return ['meetingDate' => $meetingDate, 'items' => $confirmed];
    }//end confirmAgenda()

    /**
     * Generate an ordered agenda document (hamerstukken first) via Docudesk.
     *
     * Returns the ordered item structure that backs the document. When Docudesk
     * is available the document is created and linked; otherwise the ordered
     * structure is still returned so the caller can render or retry.
     *
     * @param array<int, string> $caseIds The case UUIDs on the agenda.
     *
     * @return array<string, mixed> {items: [...], documentId?: string}
     *
     * @throws RuntimeException When OpenRegister/config is unavailable.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    public function generateAgendaDocument(array $caseIds): array
    {
        [$objectService, $register, $caseSchema] = $this->bootstrapCase();

        $hamerstukken    = [];
        $bespreekstukken = [];
        foreach ($caseIds as $caseId) {
            $caseId = (string) $caseId;
            if ($caseId === '') {
                continue;
            }

            $case         = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
            $behandeling  = $this->getCaseProperty(
                objectService: $objectService,
                register: $register,
                caseId: $caseId,
                name: 'behandeling',
            );
            $agendanummer = $this->getCaseProperty(
                objectService: $objectService,
                register: $register,
                caseId: $caseId,
                name: 'agendanummer',
            );

            $entry = [
                'case'         => $caseId,
                'title'        => (string) ($case['title'] ?? ''),
                'agendanummer' => $agendanummer,
                'behandeling'  => $behandeling,
            ];

            if ($behandeling === self::BEHANDELING_BESPREEKSTUK) {
                $bespreekstukken[] = $entry;
                continue;
            }

            $hamerstukken[] = $entry;
        }//end foreach

        $ordered = array_merge($hamerstukken, $bespreekstukken);

        $documentId = $this->renderViaDocudesk(items: $ordered);

        $result = ['items' => $ordered];
        if ($documentId !== '') {
            $result['documentId'] = $documentId;
        }

        return $result;
    }//end generateAgendaDocument()

    /**
     * Render the agenda via Docudesk when its service is available.
     *
     * @param array<int, array<string, mixed>> $items The ordered agenda items.
     *
     * @return string The created document id, or empty string when Docudesk is unavailable.
     */
    private function renderViaDocudesk(array $items): string
    {
        try {
            if ($this->container->has('OCA\Docudesk\Service\DocumentService') === false) {
                $this->logger->info(
                    'Procest: Docudesk not available; returning ordered agenda items only',
                    ['app' => Application::APP_ID],
                );
                return '';
            }

            $documentService = $this->container->get('OCA\Docudesk\Service\DocumentService');
            if (method_exists($documentService, 'createFromData') === false) {
                return '';
            }

            $document = $documentService->createFromData(
                [
                    'type'  => 'agenda',
                    'title' => 'Vergaderagenda',
                    'items' => $items,
                ],
            );

            if (is_object($document) === true && method_exists($document, 'getId') === true) {
                return (string) $document->getId();
            }

            return '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: Docudesk agenda generation failed',
                ['exception' => $e->getMessage()],
            );
            return '';
        }//end try
    }//end renderViaDocudesk()

    /**
     * Notify the steller and portefeuillehouder that their case was agendized.
     *
     * @param array<string, mixed> $case The case payload.
     *
     * @return void
     */
    private function notifyAgendered(array $case): void
    {
        try {
            $manager = $this->container->get(IManager::class);
            $caseId  = (string) ($case['id'] ?? $case['uuid'] ?? '');

            foreach (['steller', 'portefeuillehouder'] as $field) {
                $user = (string) ($case[$field] ?? '');
                if ($user === '') {
                    continue;
                }

                $notification = $manager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($user)
                    ->setDateTime(new DateTime())
                    ->setObject('case', $caseId)
                    ->setSubject('case_agendered', ['title' => (string) ($case['title'] ?? '')]);
                $manager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Procest: agenda notification failed', ['exception' => $e->getMessage()]);
        }//end try
    }//end notifyAgendered()

    /**
     * Resolve a caseType id by title.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $title         The caseType title.
     *
     * @return string The caseType id, or empty string.
     */
    private function resolveCaseTypeId(object $objectService, string $register, string $title): string
    {
        $schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
        if ($schema === '') {
            return '';
        }

        $results = $objectService->findAll(
            ['filters' => ['register' => $register, 'schema' => $schema, 'title' => $title], 'limit' => 1],
        );
        if (is_array($results) === true && isset($results['results']) === true) {
            $results = $results['results'];
        }

        if (is_array($results) === true && count($results) > 0) {
            $first = $this->toArray(value: $results[0]);
            return (string) ($first['id'] ?? $first['uuid'] ?? '');
        }

        return '';
    }//end resolveCaseTypeId()

    /**
     * Resolve a statusType id by gremium title + status name.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $gremium       The caseType title.
     * @param string $statusName    The statusType name.
     *
     * @return string The status id, or empty string.
     */
    private function resolveStatusForGremium(
        object $objectService,
        string $register,
        string $gremium,
        string $statusName,
    ): string {
        $caseTypeId = $this->resolveCaseTypeId(objectService: $objectService, register: $register, title: $gremium);
        if ($caseTypeId === '') {
            return '';
        }

        return $this->resolveStatusTypeId(
            objectService: $objectService,
            register: $register,
            caseTypeId: $caseTypeId,
            name: $statusName,
        );
    }//end resolveStatusForGremium()

    /**
     * Resolve a statusType id from a case's caseType + status name.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      The register slug.
     * @param array<string, mixed> $case          The case payload.
     * @param string               $statusName    The statusType name.
     *
     * @return string The status id, or empty string.
     */
    private function resolveStatusForCase(
        object $objectService,
        string $register,
        array $case,
        string $statusName,
    ): string {
        $caseTypeId = (string) ($case['caseType'] ?? '');
        if ($caseTypeId === '') {
            return '';
        }

        return $this->resolveStatusTypeId(
            objectService: $objectService,
            register: $register,
            caseTypeId: $caseTypeId,
            name: $statusName,
        );
    }//end resolveStatusForCase()

    /**
     * Resolve a statusType id by caseType + name.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $caseTypeId    The owning caseType id.
     * @param string $name          The statusType name.
     *
     * @return string The status id, or empty string.
     */
    private function resolveStatusTypeId(
        object $objectService,
        string $register,
        string $caseTypeId,
        string $name,
    ): string {
        $schema = $this->settingsService->getConfigValue(key: 'status_type_schema');
        if ($schema === '') {
            return '';
        }

        $results = $objectService->findAll(
            ['filters' => ['register' => $register, 'schema' => $schema, 'caseType' => $caseTypeId, 'name' => $name], 'limit' => 1],
        );
        if (is_array($results) === true && isset($results['results']) === true) {
            $results = $results['results'];
        }

        if (is_array($results) === true && count($results) > 0) {
            $first = $this->toArray(value: $results[0]);
            return (string) ($first['id'] ?? $first['uuid'] ?? '');
        }

        return '';
    }//end resolveStatusTypeId()

    /**
     * Upsert a caseProperty value.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $caseId        The case UUID.
     * @param string $name          The property name.
     * @param string $value         The property value.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — caseProperty fields.
     */
    private function upsertCaseProperty(
        object $objectService,
        string $register,
        string $caseId,
        string $name,
        string $value,
    ): void {
        $schema = $this->settingsService->getConfigValue(key: 'case_property_schema');
        if ($schema === '') {
            return;
        }

        $existingId = '';
        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema, 'case' => $caseId, 'name' => $name], 'limit' => 1],
            );
            if (is_array($results) === true && isset($results['results']) === true) {
                $results = $results['results'];
            }

            if (is_array($results) === true && count($results) > 0) {
                $first      = $this->toArray(value: $results[0]);
                $existingId = (string) ($first['id'] ?? $first['uuid'] ?? '');
            }

            $payload = ['case' => $caseId, 'name' => $name, 'value' => $value];
            if ($existingId !== '') {
                $objectService->saveObject($register, $schema, $payload, $existingId);
                return;
            }

            $objectService->saveObject($register, $schema, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: could not upsert caseProperty',
                ['name' => $name, 'exception' => $e->getMessage()],
            );
        }//end try
    }//end upsertCaseProperty()

    /**
     * Read a caseProperty value.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $caseId        The case UUID.
     * @param string $name          The property name.
     *
     * @return string The property value, or empty string.
     */
    private function getCaseProperty(object $objectService, string $register, string $caseId, string $name): string
    {
        $schema = $this->settingsService->getConfigValue(key: 'case_property_schema');
        if ($schema === '') {
            return '';
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema, 'case' => $caseId, 'name' => $name], 'limit' => 1],
            );
            if (is_array($results) === true && isset($results['results']) === true) {
                $results = $results['results'];
            }

            if (is_array($results) === true && count($results) > 0) {
                $first = $this->toArray(value: $results[0]);
                return (string) ($first['value'] ?? '');
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Procest: could not read caseProperty', ['name' => $name, 'exception' => $e->getMessage()]);
        }

        return '';
    }//end getCaseProperty()

    /**
     * Resolve ObjectService + (register, case schema).
     *
     * @return array{0: object, 1: string, 2: string}
     *
     * @throws RuntimeException When unavailable.
     */
    private function bootstrapCase(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            throw new RuntimeException('Case-schema is niet geconfigureerd');
        }

        return [$objectService, $register, $caseSchema];
    }//end bootstrapCase()

    /**
     * Resolve ObjectService + register for caseProperty operations.
     *
     * @return array{0: object, 1: string}
     *
     * @throws RuntimeException When unavailable.
     */
    private function bootstrapProperty(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        if ($register === '') {
            throw new RuntimeException('Register is niet geconfigureerd');
        }

        return [$objectService, $register];
    }//end bootstrapProperty()

    /**
     * Convert an ObjectService return value to an associative array.
     *
     * @param mixed $value The returned object/array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($value) === true) {
            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
