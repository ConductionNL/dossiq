<?php

/**
 * Dossiq Catalogue Controller
 *
 * GET /api/catalogue/object-types: the object types the catalogue page
 * (board DqCatalogus) lists, which are the schemas in dossiq's own register.
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
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Catalogue\ObjectTypeCatalogue;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The catalogue page's object types.
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */
class CatalogueController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The current HTTP request.
	 * @param ObjectTypeCatalogue $objectTypes The object type list.
	 * @param LoggerInterface     $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectTypeCatalogue $objectTypes,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The object types in dossiq's register.
	 *
	 * Admin-only, like the catalogue page that reads it. When OpenRegister
	 * cannot read the register the answer is a 503, so the page does not
	 * mistake a failed read for a register without object types.
	 *
	 * @return JSONResponse `{results: array, total: int}`, or 503 with `error`.
	 *
	 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function objectTypes(): JSONResponse {
		try {
			$rows = $this->objectTypes->list();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the object types of its register',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['results' => [], 'total' => 0, 'error' => 'The object types could not be read'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end objectTypes()
}//end class
