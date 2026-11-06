<?php

/**
 * Dossiq StorePackageController
 *
 * The case type packages the marketplace page lists while no registry is
 * configured, and the install an administrator starts from a card.
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
 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Setup\CaseTypePackageChoice;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists the shipped packages and installs one.
 *
 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md
 */
class StorePackageController extends Controller {

	/**
	 * Kebab-case pattern of a package id.
	 */
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request The request.
	 * @param CaseTypePackageChoice $choice  The packages on offer and their install.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseTypePackageChoice $choice,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The packages. Browsing is open to every signed-in user: this names case
	 * types and versions, and holds no case data.
	 *
	 * @return JSONResponse `{ packages }`.
	 *
	 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md#requirement-the-marketplace-lists-the-shipped-packages
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: ['packages' => $this->choice->shipped()]);
	}//end index()

	/**
	 * Install one on-request package. Administrators only, checked by the
	 * attribute before this body runs.
	 *
	 * @param string $id The package id.
	 *
	 * @return JSONResponse 200 with the outcome; 400 for a malformed id; 404 for an id not on offer; 422 for a refusal.
	 *
	 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md#requirement-an-administrator-installs-a-shipped-package
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function install(string $id): JSONResponse {
		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed package id.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->choice->isOffered(id: $id) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'This package cannot be installed from here.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$outcome = $this->choice->install(id: $id);
		if ($outcome['success'] === false) {
			return new JSONResponse(data: $outcome, statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(data: $outcome);
	}//end install()
}//end class
