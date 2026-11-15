<?php

/**
 * Dossiq MapsZgwRows support trait.
 *
 * The list, outbound-mapping and logged-failure steps the ZGW controllers and
 * their handlers share. The using class provides a `$zgwService` property
 * ({@see \OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface}) and composes
 * {@see NormalisesObjectRows}.
 *
 * @category Support
 * @package  OCA\Dossiq\Support
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
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Search, map and fail the way every ZGW list and write does.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
trait MapsZgwRows {
	/**
	 * The stored rows matching the ZGW query parameters, at most 100.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param array<string, mixed> $mappingConfig The resource's mapping configuration.
	 * @param array<string, mixed> $params        The request's query parameters.
	 *
	 * @return array<mixed> The matching rows.
	 */
	private function filteredRows(object $objectService, array $mappingConfig, array $params): array {
		$filters = $this->zgwService->translateQueryParams(
			params: $params,
			mappingConfig: $mappingConfig
		);

		$searchParams = array_merge($filters, ['_limit' => 100]);

		$query = $objectService->buildSearchQuery(
			requestParams: $searchParams,
			register: $mappingConfig['sourceRegister'],
			schema: $mappingConfig['sourceSchema']
		);
		$result = $objectService->searchObjectsPaginated(query: $query);

		return $result['results'] ?? [];
	}//end filteredRows()

	/**
	 * Each row mapped to its ZGW representation.
	 *
	 * @param array<mixed>         $rows          The stored rows.
	 * @param array<string, mixed> $mappingConfig The resource's mapping configuration.
	 * @param string               $baseUrl       The resource's base URL.
	 *
	 * @return array<int, array<string, mixed>> The mapped rows.
	 */
	private function outboundRows(array $rows, array $mappingConfig, string $baseUrl): array {
		$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
		$mapped = [];
		foreach ($rows as $object) {
			$mapped[] = $this->zgwService->applyOutboundMapping(
				objectData: $this->objectToArray(row: $object),
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);
		}

		return $mapped;
	}//end outboundRows()

	/**
	 * Log a failure and answer with a 500 that does not leak its message.
	 *
	 * @param string     $logPrefix The log message, followed by the failure's message.
	 * @param \Throwable $e         The failure.
	 *
	 * @return JSONResponse
	 */
	private function loggedServerError(string $logPrefix, \Throwable $e): JSONResponse {
		$this->zgwService->getLogger()->error(
			$logPrefix . $e->getMessage(),
			['exception' => $e]
		);
		return new JSONResponse(
			data: ['detail' => 'Internal server error'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end loggedServerError()

	/**
	 * Log a failure and answer with a 400 carrying its message.
	 *
	 * @param string     $logPrefix The log message, followed by the failure's message.
	 * @param \Throwable $e         The failure.
	 *
	 * @return JSONResponse
	 */
	private function loggedFailure(string $logPrefix, \Throwable $e): JSONResponse {
		$this->zgwService->getLogger()->error(
			$logPrefix . $e->getMessage(),
			['exception' => $e]
		);
		return new JSONResponse(
			data: ['detail' => $e->getMessage()],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end loggedFailure()
}//end trait
