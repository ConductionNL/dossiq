<?php

/**
 * Dossiq registry object write.
 *
 * Saves a mandate registry object from the request body the way the mandate
 * controllers answer it: without the route and identity keys, 201 for a
 * create, 200 for an update, 422 for a save the registry refuses and a
 * logged 500 for anything else.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Mandaat\MandaatRegistryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates and updates mandate registry objects from a request body.
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 */
final class RegistryObjectWrite {

	/**
	 * The registry service.
	 *
	 * @var MandaatRegistryService
	 */
	public MandaatRegistryService $registry;

	/**
	 * The request whose body is saved.
	 *
	 * @var IRequest
	 */
	public IRequest $request;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	public LoggerInterface $logger;

	/**
	 * Create a registry object; 201 on success.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 *
	 * @return JSONResponse The saved object, or an error.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function create(string $schemaConfigKey): JSONResponse {
		return $this->persist(schemaConfigKey: $schemaConfigKey, id: null);
	}//end create()

	/**
	 * Update a registry object; 200 on success.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param string $id              The existing id.
	 *
	 * @return JSONResponse The saved object, or an error.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function update(string $schemaConfigKey, string $id): JSONResponse {
		return $this->persist(schemaConfigKey: $schemaConfigKey, id: $id);
	}//end update()

	/**
	 * Save a registry object from the request body.
	 *
	 * @param string      $schemaConfigKey Config key naming the schema.
	 * @param string|null $id              Existing id, or null to create.
	 *
	 * @return JSONResponse The saved object, or an error.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	private function persist(string $schemaConfigKey, ?string $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$saved = $this->registry->save(schemaConfigKey: $schemaConfigKey, data: $data, id: $id);
			return new JSONResponse(data: $saved, statusCode: $this->successStatus(id: $id));
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to save mandate registry object (' . $schemaConfigKey . '): ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to save: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end persist()

	/**
	 * The status a successful save answers: 201 for a create, 200 for an update.
	 *
	 * @param string|null $id Existing id, or null for a create.
	 *
	 * @return int
	 */
	private function successStatus(?string $id): int {
		if ($id === null) {
			return Http::STATUS_CREATED;
		}

		return Http::STATUS_OK;
	}//end successStatus()
}//end class
