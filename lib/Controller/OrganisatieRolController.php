<?php

/**
 * Dossiq Organisatie Rol Controller
 *
 * The admin settings surface for the role hierarchy and person-to-role
 * assignments: OrganisatieRollen and MedewerkerRolToewijzingen.
 *
 * Split from MandaatRegistryController, which owns the *decision* registries
 * (MandateringsBesluiten and Mandaten). Both close procest#794: the shipped
 * admin panel already called `/api/mandate/rollen` and
 * `/api/mandate/toewijzingen`, neither of which was declared in
 * `appinfo/routes.php`, so Nextcloud answered both with its own HTML page under
 * HTTP 200 and the tabs rendered silently empty.
 *
 * ⚠️ Every method carries `#[AuthorizedAdminSetting]`. The alternative —
 * repointing the frontend at OpenRegister's generic object route — also
 * resolves and also removes the symptom, and bypasses dossiq's admin
 * authorization; that was the retracted first fix for the sibling issue
 * procest#784.
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
 * @spec openspec/specs/mandaat-matrix/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\RegistryObjectWrite;
use OCA\Dossiq\Service\Mandaat\MandaatRegistryService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Admin CRUD for OrganisatieRollen and MedewerkerRolToewijzingen.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 */
class OrganisatieRolController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param MandaatRegistryService $registry The registry service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MandaatRegistryService $registry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the OrganisatieRollen.
	 *
	 * @return JSONResponse The roles as a JSON array.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function rollenIndex(): JSONResponse {
		return new JSONResponse(
			data: $this->registry->list(schemaConfigKey: MandaatRegistryService::SCHEMA_ROL),
			statusCode: Http::STATUS_OK
		);
	}//end rollenIndex()

	/**
	 * Create an OrganisatieRol.
	 *
	 * @return JSONResponse The created role.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function rollenCreate(): JSONResponse {
		return $this->writes()->create(schemaConfigKey: MandaatRegistryService::SCHEMA_ROL);
	}//end rollenCreate()

	/**
	 * Update an OrganisatieRol.
	 *
	 * @param string $id The role id.
	 *
	 * @return JSONResponse The updated role.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function rollenUpdate(string $id): JSONResponse {
		return $this->writes()->update(schemaConfigKey: MandaatRegistryService::SCHEMA_ROL, id: $id);
	}//end rollenUpdate()

	/**
	 * Delete an OrganisatieRol, refusing when it is still referenced.
	 *
	 * A role held by a Mandaat or by an active MedewerkerRolToewijzing is
	 * refused with `409 Conflict` and a message naming what blocks it — the
	 * referential-integrity guard the spec requires.
	 *
	 * @param string $id The role id.
	 *
	 * @return JSONResponse Confirmation, or the refusal.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function rollenDestroy(string $id): JSONResponse {
		try {
			$this->registry->deleteRole(id: $id);
			return new JSONResponse(data: ['message' => 'Deleted'], statusCode: Http::STATUS_OK);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_CONFLICT
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to delete OrganisatieRol ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to delete role: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end rollenDestroy()

	/**
	 * List the MedewerkerRolToewijzingen.
	 *
	 * @return JSONResponse The assignments as a JSON array.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function toewijzingenIndex(): JSONResponse {
		return new JSONResponse(
			data: $this->registry->list(schemaConfigKey: MandaatRegistryService::SCHEMA_TOEWIJZING),
			statusCode: Http::STATUS_OK
		);
	}//end toewijzingenIndex()

	/**
	 * Create a MedewerkerRolToewijzing.
	 *
	 * @return JSONResponse The created assignment.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function toewijzingenCreate(): JSONResponse {
		return $this->writes()->create(schemaConfigKey: MandaatRegistryService::SCHEMA_TOEWIJZING);
	}//end toewijzingenCreate()

	/**
	 * Update a MedewerkerRolToewijzing — including ending it.
	 *
	 * ⚠️ `saveObject()` is PUT-semantic, so the caller must send the whole
	 * assignment, not just the field it is changing. Ending an assignment means
	 * sending every property with `validUntil` overridden.
	 *
	 * @param string $id The assignment id.
	 *
	 * @return JSONResponse The updated assignment.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function toewijzingenUpdate(string $id): JSONResponse {
		return $this->writes()->update(schemaConfigKey: MandaatRegistryService::SCHEMA_TOEWIJZING, id: $id);
	}//end toewijzingenUpdate()

	/**
	 * Writes registry objects from this request's body.
	 *
	 * @return RegistryObjectWrite
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	private function writes(): RegistryObjectWrite {
		$writes = new RegistryObjectWrite();
		$writes->registry = $this->registry;
		$writes->request = $this->request;
		$writes->logger = $this->logger;
		return $writes;
	}//end writes()
}//end class
