<?php

/**
 * Procest Besluitvorming Template Service
 *
 * Seeds the pre-configured bestuurlijke-besluitvorming zaaktype bundles
 * (College-besluit, Raadsbesluit, Mandaatbesluit) into OpenRegister. Each
 * bundle activates a caseType plus its statusType, propertyDefinition,
 * roleType, documentType, resultType, workflowTemplate, and default
 * parafeerroute records. Activation is idempotent — re-running it does not
 * duplicate records (existing caseTypes are detected by identifier).
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seeds besluitvorming zaaktype templates into OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates ObjectService + config.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class BesluitvormingTemplateService
{
    use SearchesObjects;

    /**
     * Recognised template slugs and their backing JSON files.
     *
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'college-besluit' => 'bvw-college-besluit.json',
        'raadsbesluit'    => 'bvw-raadsbesluit.json',
        'mandaatbesluit'  => 'bvw-mandaatbesluit.json',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister + app config.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Activate (seed) all three besluitvorming templates.
     *
     * @return array<string, mixed> Per-template result summary.
     *
     * @spec openspec/specs/besluitvorming-workflow/spec.md
     */
    public function activateAll(): array
    {
        $summary = [];
        foreach (array_keys(self::TEMPLATES) as $slug) {
            try {
                $summary[$slug] = $this->activate(slug: $slug);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: failed to activate besluitvorming template',
                    ['slug' => $slug, 'exception' => $e->getMessage(), 'app' => Application::APP_ID],
                );
                $summary[$slug] = ['success' => false, 'message' => 'activation_failed'];
            }
        }

        return $summary;
    }//end activateAll()

    /**
     * Activate a single besluitvorming template by slug.
     *
     * Reads the template JSON bundle and upserts its caseType + related
     * records into OpenRegister. Idempotent: if a caseType with the same
     * identifier already exists, no records are created.
     *
     * @param string $slug The template slug (college-besluit|raadsbesluit|mandaatbesluit).
     *
     * @return array<string, mixed> A result summary with creation counts.
     *
     * @throws RuntimeException When the slug is unknown or the bundle cannot be read.
     *
     * @spec openspec/specs/besluitvorming-workflow/spec.md
     */
    public function activate(string $slug): array
    {
        if (isset(self::TEMPLATES[$slug]) === false) {
            throw new RuntimeException('Onbekend besluitvorming-template: '.$slug);
        }

        $bundle = $this->loadBundle(slug: $slug);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schemas  = $this->resolveSchemas();
        if ($register === '' || $schemas['caseType'] === '') {
            throw new RuntimeException('Register of caseType-schema is niet geconfigureerd');
        }

        $caseTypeData = (array) ($bundle['caseType'] ?? []);
        $identifier   = (string) ($caseTypeData['identifier'] ?? '');
        if ($identifier === '') {
            throw new RuntimeException('Template mist een caseType.identifier');
        }

        // This service is only ever invoked from the boot-time
        // SeedBesluitvormingTemplates repair step — never from a live user
        // request — so it is safe to elevate the idempotency read + bundle
        // writes below for the duration of this call. Anonymous callers are
        // otherwise fail-closed by OpenRegister RBAC (#1955) on every boot.
        return $this->runAsSystemIfAvailable(
            objectService: $objectService,
            operation: function () use ($objectService, $register, $schemas, $slug, $caseTypeData, $bundle, $identifier): array {
                // Idempotency: skip if a caseType with this identifier already exists.
                $existing = $this->findByIdentifier(
                    objectService: $objectService,
                    register: $register,
                    schema: $schemas['caseType'],
                    identifier: $identifier,
                );
                if ($existing !== null) {
                    $this->logger->info(
                        'Procest: besluitvorming template already active, skipping',
                        ['slug' => $slug, 'identifier' => $identifier],
                    );
                    return ['success' => true, 'skipped' => true, 'slug' => $slug];
                }

                // The default parafeerroute lives either at the bundle top level or
                // nested under caseType; accept both shapes.
                $parafeerroute = (array) ($bundle['parafeerroute'] ?? ($caseTypeData['parafeerroute'] ?? []));
                unset($caseTypeData['parafeerroute']);

                return $this->seedBundle(
                    objectService: $objectService,
                    register: $register,
                    schemas: $schemas,
                    slug: $slug,
                    caseTypeData: $caseTypeData,
                    parafeerroute: $parafeerroute,
                );
            }
        );
    }//end activate()

    /**
     * Seed all records of a bundle once idempotency has been cleared.
     *
     * @param object                $objectService The OpenRegister ObjectService.
     * @param string                $register      The register slug.
     * @param array<string, string> $schemas       Map of schema-key => schema id.
     * @param string                $slug          The template slug.
     * @param array<string, mixed>  $caseTypeData  The caseType payload (with nested arrays).
     * @param array<string, mixed>  $parafeerroute The default parafeerroute payload.
     *
     * @return array<string, mixed> Creation counts.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — all schema ids are required.
     */
    private function seedBundle(
        object $objectService,
        string $register,
        array $schemas,
        string $slug,
        array $caseTypeData,
        array $parafeerroute,
    ): array {
        $counts = [
            'success'             => true,
            'slug'                => $slug,
            'caseType'            => 0,
            'statusTypes'         => 0,
            'roleTypes'           => 0,
            'propertyDefinitions' => 0,
            'documentTypes'       => 0,
            'resultTypes'         => 0,
            'workflowTemplate'    => 0,
            'parafeerroute'       => 0,
        ];

        $childData    = [
            'statusTypes'         => (array) ($caseTypeData['statusTypes'] ?? []),
            'roleTypes'           => (array) ($caseTypeData['roleTypes'] ?? []),
            'propertyDefinitions' => (array) ($caseTypeData['propertyDefinitions'] ?? []),
            'documentTypes'       => (array) ($caseTypeData['documentTypes'] ?? []),
            'resultTypes'         => (array) ($caseTypeData['resultTypes'] ?? []),
        ];
        $workflowData = ($caseTypeData['workflowTemplate'] ?? null);

        unset(
            $caseTypeData['statusTypes'],
            $caseTypeData['roleTypes'],
            $caseTypeData['propertyDefinitions'],
            $caseTypeData['documentTypes'],
            $caseTypeData['resultTypes'],
            $caseTypeData['workflowTemplate'],
        );

        $caseType = $this->createObject(
            objectService: $objectService,
            register: $register,
            schema: $schemas['caseType'],
            data: $caseTypeData,
        );
        if ($caseType === null) {
            return ['success' => false, 'slug' => $slug, 'message' => 'caseType_create_failed'];
        }

        $caseTypeId = $this->getObjectId(object: $caseType);
        $counts['caseType']++;

        $nameMaps = $this->seedCaseTypeChildren(
            objectService: $objectService,
            register: $register,
            schemas: $schemas,
            childData: $childData,
            caseTypeId: $caseTypeId,
            counts: $counts,
        );

        $this->seedWorkflowTemplate(
            objectService: $objectService,
            register: $register,
            schemas: $schemas,
            workflowData: $workflowData,
            nameMaps: $nameMaps,
            caseTypeId: $caseTypeId,
            counts: $counts,
        );

        $this->seedParafeerroute(
            objectService: $objectService,
            register: $register,
            schemas: $schemas,
            parafeerroute: $parafeerroute,
            caseTypeId: $caseTypeId,
            counts: $counts,
        );

        $this->logger->info('Procest: besluitvorming template activated', $counts);

        return $counts;
    }//end seedBundle()

    /**
     * Seed the five child collections of a caseType.
     *
     * @param object                           $objectService The OpenRegister ObjectService.
     * @param string                           $register      The register slug.
     * @param array<string, string>            $schemas       Map of schema-key => schema id.
     * @param array<string, array<int, mixed>> $childData     Child payloads keyed by collection.
     * @param string                           $caseTypeId    The parent caseType id.
     * @param array<string, mixed>             $counts        Counts accumulator (by reference).
     *
     * @return array<string, array<string, string>> Name => id maps per collection.
     */
    private function seedCaseTypeChildren(
        object $objectService,
        string $register,
        array $schemas,
        array $childData,
        string $caseTypeId,
        array &$counts,
    ): array {
        $nameMaps    = [];
        $collections = [
            'statusTypes'         => 'statusType',
            'roleTypes'           => 'roleType',
            'propertyDefinitions' => 'propertyDefinition',
            'documentTypes'       => 'documentType',
            'resultTypes'         => 'resultType',
        ];

        foreach ($collections as $countKey => $schemaKey) {
            $nameMaps[$countKey] = $this->seedChildren(
                objectService: $objectService,
                register: $register,
                schema: $schemas[$schemaKey],
                records: $childData[$countKey],
                caseTypeId: $caseTypeId,
                counts: $counts,
                countKey: $countKey,
            );
        }//end foreach

        return $nameMaps;
    }//end seedCaseTypeChildren()

    /**
     * Seed the workflow template, resolving its name references first.
     *
     * @param object                               $objectService The OpenRegister ObjectService.
     * @param string                               $register      The register slug.
     * @param array<string, string>                $schemas       Map of schema-key => schema id.
     * @param mixed                                $workflowData  The raw workflow payload, if any.
     * @param array<string, array<string, string>> $nameMaps      Name => id maps per collection.
     * @param string                               $caseTypeId    The owning caseType id.
     * @param array<string, mixed>                 $counts        Counts accumulator (by reference).
     *
     * @return void
     */
    private function seedWorkflowTemplate(
        object $objectService,
        string $register,
        array $schemas,
        mixed $workflowData,
        array $nameMaps,
        string $caseTypeId,
        array &$counts,
    ): void {
        if (is_array($workflowData) === false || $schemas['workflowTemplate'] === '') {
            return;
        }

        $resolved = $this->resolveWorkflowReferences(
            workflowData: $workflowData,
            statusNameMap: $nameMaps['statusTypes'],
            roleNameMap: $nameMaps['roleTypes'],
            caseTypeId: $caseTypeId,
        );
        $created  = $this->createObject(
            objectService: $objectService,
            register: $register,
            schema: $schemas['workflowTemplate'],
            data: $resolved,
        );
        if ($created !== null) {
            $counts['workflowTemplate']++;
        }
    }//end seedWorkflowTemplate()

    /**
     * Seed the default parafeerroute for a caseType.
     *
     * @param object                $objectService The OpenRegister ObjectService.
     * @param string                $register      The register slug.
     * @param array<string, string> $schemas       Map of schema-key => schema id.
     * @param array<string, mixed>  $parafeerroute The default parafeerroute payload.
     * @param string                $caseTypeId    The owning caseType id.
     * @param array<string, mixed>  $counts        Counts accumulator (by reference).
     *
     * @return void
     */
    private function seedParafeerroute(
        object $objectService,
        string $register,
        array $schemas,
        array $parafeerroute,
        string $caseTypeId,
        array &$counts,
    ): void {
        if (empty($parafeerroute) === true || $schemas['parafeerroute'] === '') {
            return;
        }

        $parafeerroute['caseType'] = $caseTypeId;
        $createdRoute = $this->createObject(
            objectService: $objectService,
            register: $register,
            schema: $schemas['parafeerroute'],
            data: $parafeerroute,
        );
        if ($createdRoute !== null) {
            $counts['parafeerroute']++;
        }
    }//end seedParafeerroute()

    /**
     * Seed a list of child records linked to a caseType, returning a name->id map.
     *
     * @param object               $objectService The OpenRegister ObjectService.
     * @param string               $register      The register slug.
     * @param string               $schema        The child schema id.
     * @param array<int, mixed>    $records       The child record payloads.
     * @param string               $caseTypeId    The parent caseType id.
     * @param array<string, mixed> $counts        Counts accumulator (by reference).
     * @param string               $countKey      The key in $counts to increment.
     *
     * @return array<string, string> Map of record name => created id.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — counts + key needed for tally.
     */
    private function seedChildren(
        object $objectService,
        string $register,
        string $schema,
        array $records,
        string $caseTypeId,
        array &$counts,
        string $countKey,
    ): array {
        $nameToId = [];
        if ($schema === '') {
            return $nameToId;
        }

        foreach ($records as $record) {
            if (is_array($record) === false) {
                continue;
            }

            $record['caseType'] = $caseTypeId;
            $created            = $this->createObject(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                data: $record,
            );
            if ($created === null) {
                continue;
            }

            $name = (string) ($record['name'] ?? '');
            if ($name !== '') {
                $nameToId[$name] = $this->getObjectId(object: $created);
            }

            $counts[$countKey]++;
        }//end foreach

        return $nameToId;
    }//end seedChildren()

    /**
     * Resolve workflow step/transition name references to created UUIDs.
     *
     * @param array<string, mixed>  $workflowData  The raw workflow template payload.
     * @param array<string, string> $statusNameMap Map of statusType name => id.
     * @param array<string, string> $roleNameMap   Map of roleType name => id.
     * @param string                $caseTypeId    The owning caseType id.
     *
     * @return array<string, mixed> The workflow payload with resolved references.
     */
    private function resolveWorkflowReferences(
        array $workflowData,
        array $statusNameMap,
        array $roleNameMap,
        string $caseTypeId,
    ): array {
        $workflowData['caseType'] = $caseTypeId;

        $workflowData['steps'] = json_encode(
            $this->resolveWorkflowSteps(
                steps: (array) ($workflowData['steps'] ?? []),
                statusNameMap: $statusNameMap,
            )
        );

        $workflowData['transitions'] = json_encode(
            $this->resolveWorkflowTransitions(
                transitions: (array) ($workflowData['transitions'] ?? []),
                statusNameMap: $statusNameMap,
                roleNameMap: $roleNameMap,
            )
        );

        return $workflowData;
    }//end resolveWorkflowReferences()

    /**
     * Resolve the statusName reference on every workflow step.
     *
     * @param array<int, mixed>     $steps         The raw workflow steps.
     * @param array<string, string> $statusNameMap Map of statusType name => id.
     *
     * @return array<int, array<string, mixed>> The resolved steps.
     */
    private function resolveWorkflowSteps(array $steps, array $statusNameMap): array
    {
        $resolvedSteps = [];
        foreach ($steps as $step) {
            if (is_array($step) === false) {
                continue;
            }

            $statusName = (string) ($step['statusName'] ?? '');
            unset($step['statusName']);
            $step['id']      = $this->generateUUID();
            $step['status']  = ($statusNameMap[$statusName] ?? '');
            $resolvedSteps[] = $step;
        }//end foreach

        return $resolvedSteps;
    }//end resolveWorkflowSteps()

    /**
     * Resolve the status and role references on every workflow transition.
     *
     * @param array<int, mixed>     $transitions   The raw workflow transitions.
     * @param array<string, string> $statusNameMap Map of statusType name => id.
     * @param array<string, string> $roleNameMap   Map of roleType name => id.
     *
     * @return array<int, array<string, mixed>> The resolved transitions.
     */
    private function resolveWorkflowTransitions(
        array $transitions,
        array $statusNameMap,
        array $roleNameMap
    ): array {
        $resolvedTransitions = [];
        foreach ($transitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            $fromName = (string) ($transition['fromStatusName'] ?? '');
            $toName   = (string) ($transition['toStatusName'] ?? '');
            unset($transition['fromStatusName'], $transition['toStatusName']);

            $transition['id']         = $this->generateUUID();
            $transition['fromStatus'] = ($statusNameMap[$fromName] ?? '');
            if ($fromName === '*') {
                $transition['fromStatus'] = '*';
            }

            $transition['toStatus'] = ($statusNameMap[$toName] ?? '');
            $transition['guards']   = $this->resolveTransitionGuards(
                guards: (array) ($transition['guards'] ?? []),
                roleNameMap: $roleNameMap,
            );

            $resolvedTransitions[] = $transition;
        }//end foreach

        return $resolvedTransitions;
    }//end resolveWorkflowTransitions()

    /**
     * Resolve the roleName reference on every roleGuard of a transition.
     *
     * @param array<int, mixed>     $guards      The raw transition guards.
     * @param array<string, string> $roleNameMap Map of roleType name => id.
     *
     * @return array<int, mixed> The resolved guards.
     */
    private function resolveTransitionGuards(array $guards, array $roleNameMap): array
    {
        $resolvedGuards = [];
        foreach ($guards as $guard) {
            if (is_array($guard) === true
                && ($guard['type'] ?? '') === 'roleGuard'
                && isset($guard['config']['roleName']) === true
            ) {
                $guard['config']['roleId'] = ($roleNameMap[$guard['config']['roleName']] ?? '');
            }

            $resolvedGuards[] = $guard;
        }//end foreach

        return $resolvedGuards;
    }//end resolveTransitionGuards()

    /**
     * Load and decode a template JSON bundle.
     *
     * @param string $slug The template slug.
     *
     * @return array<string, mixed> The decoded bundle.
     *
     * @throws RuntimeException When the file is missing or invalid.
     */
    private function loadBundle(string $slug): array
    {
        $path = __DIR__.'/../Settings/templates/'.self::TEMPLATES[$slug];
        if (file_exists($path) === false) {
            throw new RuntimeException('Template-bestand ontbreekt: '.basename($path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Kon template-bestand niet lezen: '.basename($path));
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            throw new RuntimeException('Ongeldige JSON in template-bestand: '.basename($path));
        }

        return $decoded;
    }//end loadBundle()

    /**
     * Resolve the schema ids needed to seed a bundle.
     *
     * @return array<string, string> Map of schema-key => configured schema id.
     */
    private function resolveSchemas(): array
    {
        return [
            'caseType'           => $this->settingsService->getConfigValue(key: 'case_type_schema'),
            'statusType'         => $this->settingsService->getConfigValue(key: 'status_type_schema'),
            'roleType'           => $this->settingsService->getConfigValue(key: 'role_type_schema'),
            'propertyDefinition' => $this->settingsService->getConfigValue(key: 'property_definition_schema'),
            'documentType'       => $this->settingsService->getConfigValue(key: 'document_type_schema'),
            'resultType'         => $this->settingsService->getConfigValue(key: 'result_type_schema'),
            'workflowTemplate'   => $this->settingsService->getConfigValue(key: 'workflow_template_schema'),
            'parafeerroute'      => $this->settingsService->getConfigValue(key: 'parafeerroute_schema'),
        ];
    }//end resolveSchemas()

    /**
     * Find an existing object by its identifier field.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $register      The register slug.
     * @param string $schema        The schema id.
     * @param string $identifier    The identifier value.
     *
     * @return array<string, mixed>|null The found object, or null.
     */
    private function findByIdentifier(
        object $objectService,
        string $register,
        string $schema,
        string $identifier,
    ): ?array {
        try {
            $results = $objectService->findAll(
                [
                    'filters' => ['register' => $register, 'schema' => $schema, 'identifier' => $identifier],
                    'limit'   => 1,
                ],
            );

            if (is_array($results) === true && isset($results['results']) === true) {
                $results = $results['results'];
            }

            if (is_array($results) === true && count($results) > 0) {
                return $this->toArray(value: $results[0]);
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Procest: besluitvorming idempotency lookup failed',
                ['exception' => $e->getMessage()],
            );
            return null;
        }//end try
    }//end findByIdentifier()

    /**
     * Create an object via the ObjectService, returning null on failure.
     *
     * @param object               $objectService The OpenRegister ObjectService.
     * @param string               $register      The register slug.
     * @param string               $schema        The schema id.
     * @param array<string, mixed> $data          The object payload.
     *
     * @return object|null The created object, or null.
     */
    private function createObject(
        object $objectService,
        string $register,
        string $schema,
        array $data,
    ): ?object {
        try {
            $result = $objectService->saveObject(register: $register, schema: $schema, object: $data);
            if (is_object($result) === true) {
                return $result;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: besluitvorming seed object create failed',
                ['schema' => $schema, 'exception' => $e->getMessage()],
            );
            return null;
        }
    }//end createObject()

    /**
     * Extract an object id from an OpenRegister object.
     *
     * @param object $object The OpenRegister entity.
     *
     * @return string The id (or empty string).
     */
    private function getObjectId(object $object): string
    {
        if (method_exists($object, 'getId') === true) {
            return (string) $object->getId();
        }

        if (method_exists($object, 'getUuid') === true) {
            return (string) $object->getUuid();
        }

        return '';
    }//end getObjectId()

    /**
     * Convert an arbitrary ObjectService return value to an associative array.
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

    /**
     * Generate a UUID v4 string.
     *
     * @return string A new UUID.
     */
    private function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end generateUUID()
}//end class
