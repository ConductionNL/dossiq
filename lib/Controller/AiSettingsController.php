<?php

/**
 * Dossiq AI Settings Controller
 *
 * Administrative surface for the AI subsystem: reading and writing the AI
 * configuration and probing model connectivity. Split out of AiController so
 * that controller keeps only the per-case inference endpoints — every endpoint
 * here is admin-gated via AuthorizedAdminSetting, while every endpoint that
 * remains on AiController is a NoAdminRequired case operation. The two have
 * different callers, different auth postures and no shared state.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\AiService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only AI configuration and health API controller.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
class AiSettingsController extends Controller {
	/**
	 * Constructor for AiSettingsController.
	 *
	 * @param string $appName The application name
	 * @param IRequest $request The request object
	 * @param AiService $aiService The AI service
	 * @param SettingsService $settingsService The settings service
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private AiService $aiService,
		private SettingsService $settingsService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get AI settings.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getSettings(): JSONResponse {
		$settings = $this->aiService->getAiSettings();

		return new JSONResponse($settings);
	}//end getSettings()

	/**
	 * Update AI settings.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function updateSettings(): JSONResponse {
		$data = $this->request->getParams();
		$result = $this->settingsService->updateSettings($data);

		return new JSONResponse($result);
	}//end updateSettings()

	/**
	 * Test AI model health/connectivity.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function healthCheck(): JSONResponse {
		$result = $this->aiService->testHealth();

		return new JSONResponse($result);
	}//end healthCheck()
}//end class
