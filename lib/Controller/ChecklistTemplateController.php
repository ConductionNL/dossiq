<?php

/**
 * Checklist templates, for an administrator
 *
 * The four verbs of the template catalogue at `/api/checklists`. The earlier
 * `/api/vth/checklists` stays routed to the `*LegacyPath()` delegates.
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
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Checklist\ChecklistTemplateStore;
use OCA\Dossiq\Settings\AdminSettings;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Create, read, update and delete on checklist templates.
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class ChecklistTemplateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param ChecklistTemplateStore $templates The template catalogue
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ChecklistTemplateStore $templates,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all checklist templates.
	 *
	 * @return JSONResponse The templates, in the editor's flat view
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		$caseTypeRef = $this->request->getParam(key: 'caseTypeRef');
		$checklists = $this->templates->all(caseTypeRef: $caseTypeRef);
		return new JSONResponse(data: $checklists, statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * Create a checklist template.
	 *
	 * @return JSONResponse The created template
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		$data = $this->request->getParams();
		// Identity stripped on a CREATE too. `saveObject()` resolves its target
		// from the payload (`@self.id` first, then `id`), so a create carrying
		// either would replace an existing checklist rather than add one.
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->templates->create(data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// Same narrow arm as TaskChecklistController::submit(), for the same reason: a
			// checklist OpenRegister refuses is the author's payload, not a
			// server fault, and its message already names the property.
			$this->logger->info(
				'Rejected checklist template create: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to create checklist template: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to create checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end create()

	/**
	 * Update a checklist template.
	 *
	 * @param string $id UUID of the checklist to update
	 *
	 * @return JSONResponse The updated template
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->templates->update(id: $id, data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// Same narrow arm as TaskChecklistController::submit(), for the same reason: a
			// checklist OpenRegister refuses is the author's payload, not a
			// server fault, and its message already names the property.
			$this->logger->info(
				'Rejected checklist template update for ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to update checklist template ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to update checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end update()

	/**
	 * Delete a checklist template.
	 *
	 * @param string $id UUID of the checklist to delete
	 *
	 * @return JSONResponse Success or error
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		$success = $this->templates->delete(id: $id);
		if ($success === true) {
			return new JSONResponse(data: ['message' => 'Deleted'], statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(
			['message' => 'Failed to delete checklist'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end destroy()

	/**
	 * index() on its old address.
	 *
	 * Deprecated alias of GET /api/checklists; remove after the next major.
	 *
	 * @return JSONResponse
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function indexLegacyPath(): JSONResponse {
		return $this->index();
	}//end indexLegacyPath()

	/**
	 * create() on its old address.
	 *
	 * Deprecated alias of POST /api/checklists; remove after the next major.
	 *
	 * @return JSONResponse
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function createLegacyPath(): JSONResponse {
		return $this->create();
	}//end createLegacyPath()

	/**
	 * update() on its old address.
	 *
	 * Deprecated alias of PUT /api/checklists/{id}; remove after the next major.
	 *
	 * @param string $id UUID of the checklist to update
	 *
	 * @return JSONResponse
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function updateLegacyPath(string $id): JSONResponse {
		return $this->update(id: $id);
	}//end updateLegacyPath()

	/**
	 * destroy() on its old address.
	 *
	 * Deprecated alias of DELETE /api/checklists/{id}; remove after the next major.
	 *
	 * @param string $id UUID of the checklist to delete
	 *
	 * @return JSONResponse
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroyLegacyPath(string $id): JSONResponse {
		return $this->destroy(id: $id);
	}//end destroyLegacyPath()
}//end class
