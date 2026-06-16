<?php

/**
 * Procest Email Template Controller
 *
 * REST surface for template CRUD + draft prefill + IMAP settings.
 *
 * Strictly templating-side per ADR-022 / case-email-integration design.md —
 * sending, listing, linking, discarding live in NC Mail via the integration leaf.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @spec openspec/changes/case-email-integration/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for email-template templating + IMAP settings.
 */
class EmailTemplateController extends Controller
{

    /**
     * IMAP/poller config keys handled here.
     *
     * @var array<int, string>
     */
    private const IMAP_KEYS = [
        'email_imap_host',
        'email_imap_port',
        'email_imap_encryption',
        'email_imap_username',
        'email_imap_password',
        'email_imap_folder',
        'email_transport',
        'email_poll_interval',
        'email_poll_batch_size',
        'email_max_attachment_size',
    ];

    /**
     * Masked sensitive keys.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = ['email_imap_password'];


    /**
     * Constructor.
     *
     * @param IRequest             $request         Inbound request.
     * @param EmailTemplateService $templateService Backend templating service.
     * @param SettingsService      $settingsService Settings resolver (registers).
     * @param IAppConfig           $appConfig       App config (IMAP settings).
     * @param IUserSession         $userSession     Current user session.
     */
    public function __construct(
        IRequest $request,
        private readonly EmailTemplateService $templateService,
        private readonly SettingsService $settingsService,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * List templates for a caseType.
     *
     * @param string $caseTypeId Owning caseType id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function listTemplates(string $caseTypeId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'results' => $this->templateService->listTemplates(caseTypeId: $caseTypeId),
        ]);
    }//end listTemplates()


    /**
     * Create a new template (version 1).
     *
     * @param string $caseTypeId Owning caseType id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function createTemplate(string $caseTypeId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = [
            'name'    => (string) $this->request->getParam('name', ''),
            'subject' => (string) $this->request->getParam('subject', ''),
            'body'    => (string) $this->request->getParam('body', ''),
        ];

        try {
            return new JSONResponse($this->templateService->createTemplate(caseTypeId: $caseTypeId, data: $data));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end createTemplate()


    /**
     * Update a template (bumps version).
     *
     * @param string $templateId Existing template id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function updateTemplate(string $templateId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = [
            'name'    => $this->request->getParam('name'),
            'subject' => $this->request->getParam('subject'),
            'body'    => $this->request->getParam('body'),
        ];

        try {
            return new JSONResponse($this->templateService->updateTemplate(templateId: $templateId, data: $data));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end updateTemplate()


    /**
     * Render a draft from a template against a case.
     *
     * @param string $caseId     Case UUID.
     * @param string $templateId Template id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function prefillDraft(string $caseId, string $templateId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->templateService->prefillDraft(caseId: $caseId, templateId: $templateId));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }//end prefillDraft()


    /**
     * Read the masked IMAP / poller settings.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function getSettings(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $values = [];
        foreach (self::IMAP_KEYS as $key) {
            $raw = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            if (in_array($key, self::SENSITIVE_KEYS, true) === true) {
                $values[$key] = $raw === '' ? '' : '***';
            } else {
                $values[$key] = $raw;
            }
        }

        return new JSONResponse($values);
    }//end getSettings()


    /**
     * Persist IMAP / poller settings.
     *
     * Sensitive keys (e.g. `email_imap_password`) are stored via
     * `setValueString` with the `sensitive` flag so they are masked in
     * `occ config:list`.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function saveSettings(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        foreach (self::IMAP_KEYS as $key) {
            $value = $this->request->getParam($key);
            if ($value === null) {
                continue;
            }

            $sensitive = in_array($key, self::SENSITIVE_KEYS, true);
            // Treat `***` as "unchanged" so admins editing other fields don't blank the password.
            if ($sensitive === true && $value === '***') {
                continue;
            }

            // Sensitive keys (the shared-mailbox password) are stored with the
            // sensitive flag so they are masked in `occ config:list` and the API.
            $this->appConfig->setValueString(
                Application::APP_ID,
                $key,
                (string) $value,
                false,
                $sensitive,
            );
        }

        return new JSONResponse(['saved' => true]);
    }//end saveSettings()


    /**
     * Smoke-test the configured IMAP connection.
     *
     * Returns either `{ok: true}` or `{ok: false, error}` — never blocks the
     * UI, never throws transport errors to the caller.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function testImap(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $host = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_host', '');
        $port = (int) $this->appConfig->getValueString(Application::APP_ID, 'email_imap_port', '993');
        if ($host === '') {
            return new JSONResponse(['ok' => false, 'error' => 'imap_not_configured']);
        }

        // Best-effort TCP connect; if `imap_open()` is available we still
        // prefer that, but never throw on missing extensions.
        $errno   = 0;
        $errstr  = '';
        $handle  = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($handle === false) {
            return new JSONResponse(['ok' => false, 'error' => 'connection_failed', 'detail' => $errstr]);
        }

        fclose($handle);
        return new JSONResponse(['ok' => true]);
    }//end testImap()


    /**
     * Variable catalog for the template editor.
     *
     * @param string $caseTypeId Owning caseType id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T06
     */
    public function variables(string $caseTypeId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // SettingsService is referenced to keep the dependency live for future
        // per-type variable expansion (e.g. caseType-scoped custom fields).
        $this->settingsService->getConfigValue('register');

        return new JSONResponse($this->templateService->getAvailableVariables(caseTypeId: $caseTypeId));
    }//end variables()
}//end class
