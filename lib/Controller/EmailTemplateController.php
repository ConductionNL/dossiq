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
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Support\SuppressesWarnings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for email-template templating + IMAP settings.
 */
class EmailTemplateController extends Controller
{

    use SuppressesWarnings;

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
     * @param IGroupManager        $groupManager    Group manager (admin check on config writes).
     * @param CaseAccessGuard      $caseAccessGuard Per-case authorization (fails closed).
     */
    public function __construct(
        IRequest $request,
        private readonly EmailTemplateService $templateService,
        private readonly SettingsService $settingsService,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly CaseAccessGuard $caseAccessGuard,
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

        return new JSONResponse(
                [
                    'results' => $this->templateService->listTemplates(caseTypeId: $caseTypeId),
                ]
                );
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // An email template is instance-wide configuration whose body is sent
        // to citizens, so it is a config write, not case data: admin only.
        // There is no per-case relationship to authorise against — a caseType
        // belongs to the municipality, not to a handler.
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Same posture as createTemplate(): rewriting the body of a template
        // that is mailed to citizens is a config write.
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // The rendered draft carries the case's contactNaam, contactEmail,
        // contactTelefoon and behandelaar back in the response, so this is a
        // per-case read of citizen contact data.
        if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
            return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
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
            $raw          = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            $isSensitive  = in_array($key, self::SENSITIVE_KEYS, true);
            $values[$key] = $raw;
            if ($isSensitive === true && $raw !== '') {
                $values[$key] = '***';
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
        }//end foreach

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
        $errno  = 0;
        $errstr = '';
        $handle = $this->withoutWarnings(
            operation: static function () use ($host, $port, &$errno, &$errstr): mixed {
                return fsockopen($host, $port, $errno, $errstr, 5);
            }
        );
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

    /**
     * Seed the three Dutch default templates for a caseType.
     *
     * Idempotent: the service skips any default whose name already exists, so
     * calling this twice creates nothing the second time and returns 0.
     *
     * The auth posture mirrors createTemplate() deliberately — this endpoint
     * is a loop over exactly that call and creates nothing a caller could not
     * create one at a time, so a stricter guard here would be inconsistent
     * rather than safer.
     *
     * ⚠️ That argument was sound and the posture it mirrored was not:
     * `createTemplate()` was reachable by every authenticated user, so this
     * endpoint was too. The reasoning is kept because it is still correct —
     * what changed is the posture on the other end. Both are now admin-only.
     *
     * @param string $caseTypeId Owning caseType id.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse {created: int} — how many were created on this run.
     *
     * @spec openspec/changes/case-email-integration/tasks.md#T04
     */
    public function seedDefaults(string $caseTypeId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            return new JSONResponse(
                    [
                        'created' => $this->templateService->seedDefaultTemplates(caseTypeId: $caseTypeId),
                    ]
                    );
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end seedDefaults()
}//end class
