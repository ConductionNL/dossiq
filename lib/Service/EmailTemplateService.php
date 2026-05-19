<?php

/**
 * Procest Email Template Service
 *
 * Manages email template CRUD with version tracking: each edit creates a
 * new version object so previously sent messages retain their original
 * template content. Provides variable catalog and default Dutch template seeding.
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
 * @spec openspec/changes/case-email-integration/tasks.md#task-T04
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for email template lifecycle management and variable resolution.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T04
 */
class EmailTemplateService
{

    /**
     * Available template variable catalog grouped by source.
     *
     * @var array<string, array<string, string>>
     */
    private const VARIABLE_CATALOG = [
        'case'     => [
            'zaakNummer' => 'Zaaknummer (bijv. ZAAK-2026-000001)',
            'titel'      => 'Titel van de zaak',
            'startdatum' => 'Startdatum van de zaak',
            'deadline'   => 'Deadline van de zaak',
            'status'     => 'Huidige status van de zaak',
        ],
        'contact'  => [
            'naam'  => 'Volledige naam van de aanvrager',
            'email' => 'E-mailadres van de aanvrager',
        ],
        'caseType' => [
            'zaakTypeTitel' => 'Naam van het zaaktype',
        ],
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService App settings
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new email template for a case type.
     *
     * @param string               $caseTypeId The case type UUID
     * @param array<string, mixed> $data       Template fields (name, subject, body)
     *
     * @return array<string, mixed> The created template object
     *
     * @throws \RuntimeException If OpenRegister is not available
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T04
     */
    public function createTemplate(string $caseTypeId, array $data): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $templateSchema = $this->settingsService->getConfigValue('email_template_schema');

        $template = [
            'name'      => (string) ($data['name'] ?? ''),
            'subject'   => (string) ($data['subject'] ?? ''),
            'body'      => (string) ($data['body'] ?? ''),
            'caseType'  => $caseTypeId,
            'variables' => $this->extractVariables(template: (string) ($data['body'] ?? '')),
            'version'   => 1,
            'isActive'  => true,
        ];

        $saved = $objectService->saveObject(
            register: $register,
            schema: $templateSchema,
            object: $template,
        );

        $this->logger->info(
            'Email template created for case type '.$caseTypeId,
            ['app' => Application::APP_ID],
        );

        return $saved;
    }//end createTemplate()

    /**
     * Update an email template by creating a new version object.
     *
     * The original template is marked inactive; a new object with version+1
     * is saved. Previously sent messages retain their original templateVersion
     * reference.
     *
     * @param string               $templateId The template UUID to update
     * @param array<string, mixed> $data       Updated fields
     *
     * @return array<string, mixed> The new template version object
     *
     * @throws \RuntimeException If the template is not found
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T04
     */
    public function updateTemplate(string $templateId, array $data): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $templateSchema = $this->settingsService->getConfigValue('email_template_schema');

        $existing = $objectService->findObject(
            register: $register,
            schema: $templateSchema,
            id: $templateId,
        );

        if ($existing === null) {
            throw new \RuntimeException('Email template not found');
        }

        // Mark old version inactive.
        $objectService->saveObject(
            register: $register,
            schema: $templateSchema,
            object: array_merge($existing, ['isActive' => false]),
        );

        // Create new version.
        $newVersion = (int) ($existing['version'] ?? 1) + 1;
        $body       = (string) ($data['body'] ?? $existing['body'] ?? '');

        $newTemplate = [
            'name'      => (string) ($data['name'] ?? $existing['name'] ?? ''),
            'subject'   => (string) ($data['subject'] ?? $existing['subject'] ?? ''),
            'body'      => $body,
            'caseType'  => (string) ($existing['caseType'] ?? ''),
            'variables' => $this->extractVariables(template: $body),
            'version'   => $newVersion,
            'isActive'  => true,
        ];

        $saved = $objectService->saveObject(
            register: $register,
            schema: $templateSchema,
            object: $newTemplate,
        );

        $this->logger->info(
            'Email template updated to version '.$newVersion.' (original: '.$templateId.')',
            ['app' => Application::APP_ID],
        );

        return $saved;
    }//end updateTemplate()

    /**
     * List active email templates for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<int, array<string, mixed>> Active templates
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T04
     */
    public function listTemplates(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register       = $this->settingsService->getConfigValue('register');
        $templateSchema = $this->settingsService->getConfigValue('email_template_schema');

        if (empty($templateSchema) === true) {
            return [];
        }

        $results = $objectService->findObjects(
            register: $register,
            schema: $templateSchema,
            params: ['caseType' => $caseTypeId, 'isActive' => true],
        );

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end listTemplates()

    /**
     * Get all available template variables grouped by source.
     *
     * @return array<string, array<string, string>> Variable catalog
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T04
     */
    public function getAvailableVariables(): array
    {
        return self::VARIABLE_CATALOG;
    }//end getAvailableVariables()

    /**
     * Seed default Dutch email templates for a case type if none exist.
     *
     * Creates Ontvangstbevestiging, Informatieverzoek, and Besluit templates.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return void
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T04
     */
    public function seedDefaultTemplates(string $caseTypeId): void
    {
        $existing = $this->listTemplates(caseTypeId: $caseTypeId);
        if (count($existing) > 0) {
            return;
        }

        $defaults = [
            [
                'name'    => 'Ontvangstbevestiging',
                'subject' => '[{{zaakNummer}}] Ontvangstbevestiging - {{titel}}',
                'body'    => '<p>Geachte {{naam}},</p><p>Wij bevestigen de ontvangst van uw '
                    .'aanvraag met zaaknummer <strong>{{zaakNummer}}</strong>.</p>'
                    .'<p>De verwachte behandeltermijn is tot <strong>{{deadline}}</strong>.</p>'
                    .'<p>Met vriendelijke groet,<br>{{zaakTypeTitel}}</p>',
            ],
            [
                'name'    => 'Informatieverzoek',
                'subject' => '[{{zaakNummer}}] Aanvullende informatie gevraagd',
                'body'    => '<p>Geachte {{naam}},</p><p>In het kader van uw aanvraag '
                    .'(zaaknummer <strong>{{zaakNummer}}</strong>) verzoeken wij u aanvullende '
                    .'informatie te verstrekken.</p><p>Met vriendelijke groet,<br>'
                    .'{{zaakTypeTitel}}</p>',
            ],
            [
                'name'    => 'Besluit',
                'subject' => '[{{zaakNummer}}] Besluit op uw aanvraag',
                'body'    => '<p>Geachte {{naam}},</p><p>Wij hebben een besluit genomen op '
                    .'uw aanvraag (zaaknummer <strong>{{zaakNummer}}</strong>).</p>'
                    .'<p>Status: <strong>{{status}}</strong></p>'
                    .'<p>Met vriendelijke groet,<br>{{zaakTypeTitel}}</p>',
            ],
        ];

        foreach ($defaults as $template) {
            try {
                $this->createTemplate(caseTypeId: $caseTypeId, data: $template);
            } catch (\RuntimeException $e) {
                $this->logger->warning(
                    'Could not seed default email template: '.$e->getMessage(),
                    ['app' => Application::APP_ID],
                );
            }
        }
    }//end seedDefaultTemplates()

    /**
     * Extract variable names from a template body.
     *
     * @param string $template Template string with {{variable}} placeholders
     *
     * @return array<string> List of variable names found
     */
    private function extractVariables(string $template): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $template, $matches);
        return array_unique(array: $matches[1] ?? []);
    }//end extractVariables()
}//end class
