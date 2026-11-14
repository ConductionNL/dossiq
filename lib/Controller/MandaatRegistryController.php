<?php

/**
 * Dossiq Mandaat Registry Controller
 *
 * The admin settings surface for the mandate DECISION registries:
 * MandateringsBesluiten and Mandaten. The role hierarchy and person-to-role
 * assignments live next door in `OrganisatieRolController` — one class holding
 * all four registries exceeded the public-method budget, and the split follows
 * the domain boundary rather than an arbitrary one.
 *
 * These endpoints close procest#794. The shipped admin panel already called
 * `/api/mandate/besluiten|rollen|toewijzingen|mandaten`; none of them were
 * declared in `appinfo/routes.php`, so Nextcloud answered every one with its
 * own HTML page under HTTP 200 and four admin tabs rendered silently empty.
 *
 * ⚠️ Auth posture is deliberate and is the point of the fix. Every method here
 * carries `#[AuthorizedAdminSetting]`, matching `ChecklistTemplateController`.
 * The tempting alternative — repointing the frontend at OpenRegister's generic
 * `/apps/openregister/api/objects/dossiq/<schema>` route — resolves, returns
 * clean JSON, and makes the symptom disappear, which is exactly why it was the
 * first (wrong) fix for the sibling issue procest#784: it bypasses dossiq's
 * admin authorization and adds a second write path for a guarded resource.
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

use OCA\Dossiq\Controller\Support\RegistryObjectWrite;
use OCA\Dossiq\Kernel\Contracts\MandaatRegistryServiceInterface;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin CRUD for the mandate decision registries.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 */
class MandaatRegistryController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param MandaatRegistryServiceInterface $registry The registry service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MandaatRegistryServiceInterface $registry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the MandateringsBesluiten.
	 *
	 * @return JSONResponse The besluiten as a JSON array.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function besluiten(): JSONResponse {
		return new JSONResponse(
			data: $this->registry->list(schemaConfigKey: MandaatRegistryServiceInterface::SCHEMA_BESLUIT),
			statusCode: Http::STATUS_OK
		);
	}//end besluiten()

	/**
	 * Create a Mandaat.
	 *
	 * @return JSONResponse The created mandaat.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function mandatenCreate(): JSONResponse {
		return $this->writes()->create(schemaConfigKey: MandaatRegistryServiceInterface::SCHEMA_MANDAAT);
	}//end mandatenCreate()

	/**
	 * Update a Mandaat.
	 *
	 * @param string $id The mandaat id.
	 *
	 * @return JSONResponse The updated mandaat.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function mandatenUpdate(string $id): JSONResponse {
		return $this->writes()->update(schemaConfigKey: MandaatRegistryServiceInterface::SCHEMA_MANDAAT, id: $id);
	}//end mandatenUpdate()

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
