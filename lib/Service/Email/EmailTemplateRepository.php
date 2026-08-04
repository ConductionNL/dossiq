<?php

/**
 * Procest email template repository.
 *
 * Owns every OpenRegister read/write the emailTemplate registry needs: the
 * active-template query for a caseType, single-template load, template
 * persistence, and the case load that prefill renders against. Split out of
 * EmailTemplateService so that service keeps only the template *domain* rules
 * — version bumping, seeding, placeholder resolution — while the knowledge of
 * which register/schema pair holds which record, and how OpenRegister's
 * entity-or-array return shape collapses into a plain array, lives here.
 *
 * A missing or unconfigured register/schema is not an error on the read path:
 * it degrades to null / an empty list exactly as the pre-split service did.
 *
 * @category Service
 * @package  OCA\Procest\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Email;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * OpenRegister persistence for emailTemplate records and their cases.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T04
 */
class EmailTemplateRepository
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared OR/settings resolver.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * List the active templates for a caseType.
     *
     * @param string $caseTypeId CaseType id/slug.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T04
     */
    public function findActiveByCaseType(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_template_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $rows = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: [
                'caseType' => $caseTypeId,
                '_limit'   => 100,
            ],
        );

        return array_values(
            array_filter(
                $rows,
                static fn (array $row): bool => ($row['isActive'] ?? true) === true
            )
        );
    }//end findActiveByCaseType()

    /**
     * Persist (insert OR update) a template payload via OpenRegister.
     *
     * @param array<string, mixed> $payload Template fields.
     *
     * @return array<string, mixed> The saved object, or the payload when OR
     *                              returns an unusable shape.
     *
     * @throws RuntimeException When OpenRegister is unavailable or the
     *                          emailTemplate schema is not configured.
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T04
     */
    public function saveTemplate(array $payload): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('ObjectService unavailable');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_template_schema');
        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('emailTemplate schema is not configured');
        }

        $saved = $objectService->saveObject(
            object: $payload,
            register: $register,
            schema: $schema,
        );

        $saved = $this->toArrayOrNull(value: $saved);
        if ($saved === null) {
            return $payload;
        }

        return $saved;
    }//end saveTemplate()

    /**
     * Load a template by id/slug.
     *
     * @param string $templateId Template id.
     *
     * @return array<string, mixed>|null Null when unconfigured, unavailable or unknown.
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T04
     */
    public function findTemplate(string $templateId): ?array
    {
        return $this->findIn(configKey: 'email_template_schema', id: $templateId);
    }//end findTemplate()

    /**
     * Load a case, with the derived `_isFinal` flag merged in.
     *
     * @param string $caseId Case UUID.
     *
     * @return array<string, mixed>|null Null when unconfigured, unavailable or unknown.
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T04
     */
    public function findCase(string $caseId): ?array
    {
        $case = $this->findIn(configKey: 'case_schema', id: $caseId);
        if ($case === null) {
            return null;
        }

        $case['_isFinal'] = (empty($case['endDate']) === false);

        return $case;
    }//end findCase()

    /**
     * Fetch one object from the register schema behind the given config key.
     *
     * @param string $configKey The settings key naming the schema.
     * @param string $id        The object id/slug.
     *
     * @return array<string, mixed>|null
     */
    private function findIn(string $configKey, string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue($configKey);
        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $obj = $objectService->find($id, register: $register, schema: $schema);
        } catch (\Throwable) {
            return null;
        }

        return $this->toArrayOrNull(value: $obj);
    }//end findIn()

    /**
     * Collapse OpenRegister's entity-or-array return shape into a plain array.
     *
     * @param mixed $value The value returned by the ObjectService.
     *
     * @return array<string, mixed>|null Null when the value is neither an array
     *                                   nor a JSON-serialisable entity.
     */
    private function toArrayOrNull(mixed $value): ?array
    {
        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $value = $value->jsonSerialize();
        }

        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end toArrayOrNull()
}//end class
