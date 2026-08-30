<?php

/**
 * Dossiq ZGW OpenAPI Discovery Controller
 *
 * Publishes machine-readable OpenAPI 3.0 documents for Dossiq's routed ZGW
 * API surface (zaken, documenten, catalogi, besluiten, autorisaties,
 * notificaties), plus a JSON discovery index. Serves only static
 * documentation content — never instance data.
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
 * @spec openspec/specs/zgw-openapi-publication/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Discovery + spec-serving controller for Dossiq's ZGW OpenAPI documents.
 *
 * @spec openspec/specs/zgw-openapi-publication/spec.md
 */
class ZgwOpenApiController extends Controller {
	/**
	 * Allow-listed ZGW API ids, in the order they appear in the discovery
	 * index. Each id maps to a `docs/openapi/zgw/<id>.yaml` document and to
	 * the `/api/zgw/<id>/v1/...` route group in appinfo/routes.php.
	 *
	 * @var array<string, string>
	 */
	private const APIS = [
		'zaken' => 'Zaken (ZRC)',
		'documenten' => 'Documenten (DRC)',
		'catalogi' => 'Catalogi (ZTC)',
		'besluiten' => 'Besluiten (BRC)',
		'autorisaties' => 'Autorisaties (AC)',
		'notificaties' => 'Notificaties (NRC)',
	];

	/**
	 * The VNG ZGW standard line documented by every spec.
	 *
	 * @var string
	 */
	private const STANDARD = 'VNG ZGW 1.x';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request
	 */
	public function __construct(IRequest $request) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the implemented ZGW APIs with resolvable OpenAPI document URLs.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/zgw-openapi-publication/spec.md
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		$apis = [];
		foreach (self::APIS as $id => $name) {
			$apis[] = [
				'id' => $id,
				'name' => $name,
				'basePath' => '/api/zgw/' . $id . '/v1',
				'standard' => self::STANDARD,
				'specUrl' => $this->buildSpecUrl(api: $id),
			];
		}

		return new JSONResponse(data: ['apis' => $apis]);
	}//end index()

	/**
	 * Serve the OpenAPI 3.0 YAML document for a given ZGW API.
	 *
	 * The `$api` segment is checked against a strict allow-list (self::APIS)
	 * before touching the filesystem — no path traversal is possible.
	 *
	 * Rate-limit rationale: generous — the OpenAPI document is fetched by
	 * tooling and client generators, which a tight ceiling would break rather
	 * than protect.
	 *
	 * @param string $api The ZGW API id (zaken, documenten, catalogi, besluiten, autorisaties, notificaties)
	 *
	 * @return DataDisplayResponse|JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/zgw-openapi-publication/spec.md
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function spec(string $api): DataDisplayResponse|JSONResponse {
		if (isset(self::APIS[$api]) === false) {
			return new JSONResponse(
				data: ['detail' => 'Unknown ZGW API: ' . $api],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$path = $this->specFilePath(api: $api);
		if (is_file($path) === false) {
			return new JSONResponse(
				data: ['detail' => 'OpenAPI document not found for: ' . $api],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$yaml = file_get_contents($path);
		if ($yaml === false) {
			return new JSONResponse(
				data: ['detail' => 'Failed to read OpenAPI document for: ' . $api],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new DataDisplayResponse(
			data: $yaml,
			statusCode: Http::STATUS_OK,
			headers: ['Content-Type' => 'application/yaml']
		);
	}//end spec()

	/**
	 * Resolve the on-disk path of an allow-listed API's OpenAPI document.
	 *
	 * @param string $api The allow-listed ZGW API id
	 *
	 * @return string The absolute filesystem path
	 */
	private function specFilePath(string $api): string {
		return __DIR__ . '/../../docs/openapi/zgw/' . $api . '.yaml';
	}//end specFilePath()

	/**
	 * Build the absolute URL of an API's OpenAPI document.
	 *
	 * @param string $api The ZGW API id
	 *
	 * @return string
	 */
	private function buildSpecUrl(string $api): string {
		$scheme = $this->request->getServerProtocol();
		$serverHost = $this->request->getServerHost();

		return $scheme . '://' . $serverHost . '/index.php/apps/dossiq/api/zgw/' . $api . '/openapi.yaml';
	}//end buildSpecUrl()
}//end class
