<?php

/**
 * Dossiq Email Template Controller
 *
 * REST surface for template CRUD + draft prefill. The mail settings form is
 * EmailSettingsController's.
 *
 * Strictly templating-side per ADR-022 / case-email-integration design.md —
 * sending, listing, linking, discarding live in NC Mail via the integration leaf.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\EmailTemplateServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for email-template templating.
 *
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class EmailTemplateController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param EmailTemplateServiceInterface $templateService Backend templating service.
	 * @param SettingsServiceInterface $settingsService Settings resolver (registers).
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager (admin check on config writes).
	 * @param CaseAccessGuardInterface $caseAccessGuard Per-case authorization (fails closed).
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IRequest $request,
		private readonly EmailTemplateServiceInterface $templateService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 */
	public function listTemplates(string $caseTypeId): JSONResponse {
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
	 * Admin-only: an email template is instance-wide configuration whose body
	 * is mailed to citizens, so it is a config write, not case data. The
	 * attribute makes Nextcloud's middleware enforce that BEFORE the controller
	 * runs; the in-body check below is defence in depth and is what the unit
	 * tests drive. `@NoAdminRequired` was removed rather than left alongside an
	 * admin body — that combination is exactly the attribute-vs-body mismatch
	 * gate-9 exists to catch.
	 *
	 * @param string $caseTypeId Owning caseType id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function createTemplate(string $caseTypeId): JSONResponse {
		// An email template is instance-wide configuration whose body is sent
		// to citizens, so it is a config write, not case data: admin only.
		// There is no per-case relationship to authorise against — a caseType
		// belongs to the municipality, not to a handler.
		$refusal = $this->refuseUnlessAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$data = [
			'name' => (string)$this->request->getParam('name', ''),
			'subject' => (string)$this->request->getParam('subject', ''),
			'body' => (string)$this->request->getParam('body', ''),
			'moment' => $this->request->getParam('moment'),
			'channel' => $this->request->getParam('channel'),
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
	 * Admin-only, same posture and same reasoning as createTemplate().
	 *
	 * @param string $templateId Existing template id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function updateTemplate(string $templateId): JSONResponse {
		// Same posture as createTemplate(): rewriting the body of a template
		// that is mailed to citizens is a config write.
		$refusal = $this->refuseUnlessAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$data = $this->updateData();

		try {
			return new JSONResponse($this->templateService->updateTemplate(templateId: $templateId, data: $data));
		} catch (\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end updateTemplate()

	/**
	 * The template fields an update request carries.
	 *
	 * @return array<string, mixed> The name, subject and body, with the moment and channel the request names.
	 */
	private function updateData(): array {
		$data = [
			'name' => $this->request->getParam('name'),
			'subject' => $this->request->getParam('subject'),
			'body' => $this->request->getParam('body'),
		];

		// Only a moment or channel the request names reaches the update: an
		// explicit null clears it, an absent one keeps the current version's.
		$params = $this->request->getParams();
		foreach (['moment', 'channel'] as $field) {
			if (array_key_exists($field, $params) === true) {
				$data[$field] = $params[$field];
			}
		}

		return $data;
	}//end updateData()

	/**
	 * Render a draft from a template against a case.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $templateId Template id.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 */
	public function prefillDraft(string $caseId, string $templateId): JSONResponse {
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
	 * Variable catalog for the template editor.
	 *
	 * @param string $caseTypeId Owning caseType id.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Returns a CONSTANT. EmailTemplateService::
	 * getAvailableVariables() ignores its $caseTypeId argument entirely and
	 * returns a hardcoded three-group list of placeholder NAMES (case/contact/
	 * caseType) — it reads no object, no store and no file, so the response is
	 * byte-identical for every caller and every id. There is nothing here that
	 * a per-object guard could scope. If per-type variable expansion is ever
	 * implemented (the SettingsService call below is the placeholder for it),
	 * this exemption must be removed and the caseType read guarded.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T06
	 */
	public function variables(string $caseTypeId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// SettingsService is referenced to keep the dependency live for future
		// per-type variable expansion (e.g. caseType-scoped custom fields).
		$this->settingsService->getConfigValue('register');

		return new JSONResponse($this->templateService->getAvailableVariables(caseTypeId: $caseTypeId));
	}//end variables()

	/**
	 * The 401 for a caller without a session, the 403 for one who is not an admin, else null.
	 *
	 * @return JSONResponse|null
	 */
	private function refuseUnlessAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnlessAdmin()

}//end class
