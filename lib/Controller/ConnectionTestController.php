<?php

/**
 * Testing a configured connection from the screen that configures it.
 *
 *  - POST /api/connections/stuf/{endpointId}/test   probe one StUF endpoint
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Starter\ConnectionTestService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One button per connection screen, and it makes a real call.
 *
 * 🔑 THE ENDPOINT IS RESOLVED FROM THE STORE, NOT TAKEN OFF THE REQUEST. A test
 * that probes whatever URL the caller posts is a server-side request forgery
 * with an admin button on it: the instance would reach any host the caller
 * named and report back what it found. So the caller names a configured
 * endpoint by id and this controller looks up the URL.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */
class ConnectionTestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName The app name.
	 * @param IRequest              $request The HTTP request.
	 * @param ConnectionTestService $probe   The live probe.
	 * @param StufServices          $stuf    Access to the configured StUF endpoints.
	 * @param LoggerInterface       $logger  Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConnectionTestService $probe,
		private readonly StufServices $stuf,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Probe one configured StUF endpoint.
	 *
	 * @param string $endpointId The endpoint's id, as the endpoint list gives it.
	 *
	 * @return JSONResponse The result: the endpoint, the status, the reason and
	 *                      the moment it was measured.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function testStufEndpoint(string $endpointId): JSONResponse {
		try {
			$endpoints = $this->stuf->register->findAll(
				schema: StufRegisterAccess::SCHEMA_ENDPOINT,
				filters: ['id' => $endpointId],
				limit: 1
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not read the StUF endpoint: ' . $e->getMessage());

			return new JSONResponse(
				['error' => 'The endpoint could not be read'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$endpoint = ($endpoints[0] ?? null);
		if (is_array($endpoint) === false) {
			return new JSONResponse(['error' => 'Unknown endpoint'], Http::STATUS_NOT_FOUND);
		}

		$url = (string)($endpoint['endpointUrl'] ?? '');
		if ($url === '') {
			// Configured with no URL reads as failed, never as untested: the
			// administrator did save this connection, and it cannot work.
			return new JSONResponse(
				[
					'state' => ConnectionTestService::FAILED,
					'endpoint' => '',
					'status' => 0,
					'reason' => 'The endpoint has no URL.',
					'measuredAt' => gmdate('c'),
					'responseTimeMs' => 0,
				]
			);
		}

		return new JSONResponse($this->probe->probe(endpoint: $url));
	}//end testStufEndpoint()
}//end class
