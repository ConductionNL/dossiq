<?php

/**
 * Dossiq VTH Template Controller
 *
 * Admin endpoints for listing and activating VTH zaaktype templates.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\VTHTemplateService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for VTH zaaktype template management.
 *
 * Admin-only; activating a template creates case type configuration in
 * OpenRegister. Activation is idempotent.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/vth-module/tasks.md#task-2
 */
class VTHTemplateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param VTHTemplateService $vthTemplateService VTH template service
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-2
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly VTHTemplateService $vthTemplateService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all available VTH zaaktype templates.
	 *
	 * @return JSONResponse List of template metadata
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		$templates = $this->vthTemplateService->listTemplates();
		return new JSONResponse(data: $templates, statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * Activate a VTH zaaktype template by slug.
	 *
	 * Creates or updates the case type and all associated sub-objects in
	 * OpenRegister. Activation is idempotent — safe to call multiple times.
	 *
	 * @param string $slug The template slug (e.g. 'vth-omgevingsvergunning')
	 *
	 * @return JSONResponse Activation result or error
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function activate(string $slug): JSONResponse {
		try {
			$result = $this->vthTemplateService->activateTemplate(slug: $slug);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error(
				'VTH template activation failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			return new JSONResponse(
				['message' => 'Template activation failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end activate()
}//end class
