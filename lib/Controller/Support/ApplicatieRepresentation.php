<?php

/**
 * Dossiq applicatie representation
 *
 * Maps an OpenRegister consumer to the ZGW applicatie representation and an
 * applicatie request body back to consumer data.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * Converts between OpenRegister consumers and ZGW applicaties.
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */
final class ApplicatieRepresentation {
	/**
	 * Collect a consumer's clientIds: the primary name, then the extras.
	 *
	 * @param array $data       The serialised consumer
	 * @param array $authConfig The consumer's authorization configuration
	 *
	 * @return array List of all clientIds.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function clientIdsOf(array $data, array $authConfig): array {
		$clientIds = [];

		// Primary clientId is the consumer name.
		if (isset($data['name']) === true && $data['name'] !== '') {
			$clientIds[] = $data['name'];
		}

		// Extra clientIds stored in authorizationConfiguration.
		$extraClientIds = $authConfig['clientIds'] ?? [];
		if (is_array($extraClientIds) === true) {
			$clientIds = array_merge($clientIds, $extraClientIds);
		}

		return $clientIds;
	}//end clientIdsOf()

	/**
	 * Get all clientIds for a consumer (primary name + any extras).
	 *
	 * @param object $consumer The consumer entity.
	 *
	 * @return array List of all clientIds.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function consumerClientIds(object $consumer): array {
		$data = $consumer->jsonSerialize();

		return $this->clientIdsOf(data: $data, authConfig: ($consumer->getAuthorizationConfiguration() ?? []));
	}//end consumerClientIds()

	/**
	 * Convert a consumer entity to a ZGW applicatie representation.
	 *
	 * @param object $consumer The consumer entity.
	 * @param string $baseUrl The base URL for building resource URLs.
	 *
	 * @return array The ZGW applicatie array.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function toApplicatie(object $consumer, string $baseUrl): array {
		$authConfig = [];
		if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
			$authConfig = $consumer->getAuthorizationConfiguration() ?? [];
		}

		$data = $consumer->jsonSerialize();

		// Build clientIds: primary name + any extras from authConfig.
		$clientIds = $this->clientIdsOf(data: $data, authConfig: $authConfig);

		// Ensure clientIds is never empty.
		if (count($clientIds) === 0) {
			$clientIds = [$data['name'] ?? ''];
		}

		return [
			'url' => $baseUrl . '/' . $data['uuid'],
			'uuid' => $data['uuid'],
			'clientIds' => $clientIds,
			'label' => ($data['description'] ?? ''),
			'heeftAlleAutorisaties' => ($authConfig['superuser'] ?? false),
			'autorisaties' => ($authConfig['scopes'] ?? []),
		];
	}//end toApplicatie()

	/**
	 * Convert a ZGW applicatie request body to consumer data.
	 *
	 * Stores the first clientId as the consumer name and any additional
	 * clientIds in the authorizationConfiguration.
	 *
	 * @param array $body The request body.
	 *
	 * @return array The consumer data array.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function toConsumer(array $body): array {
		$clientIds = $body['clientIds'] ?? [];
		$name = ($body['label'] ?? 'unknown');
		$extraClientIds = [];

		if (is_array($clientIds) === true && count($clientIds) > 0) {
			$name = $clientIds[0];
			$extraClientIds = array_slice($clientIds, 1);
		}

		$authConfig = [
			'superuser' => ($body['heeftAlleAutorisaties'] ?? false),
			'scopes' => ($body['autorisaties'] ?? []),
			'algorithm' => 'HS256',
		];

		// Store extra clientIds beyond the first.
		if (count($extraClientIds) > 0) {
			$authConfig['clientIds'] = $extraClientIds;
		}

		if (isset($body['secret']) === true) {
			$authConfig['publicKey'] = $body['secret'];
		}

		return [
			'name' => $name,
			'description' => ($body['label'] ?? ''),
			'authorizationType' => 'jwt-zgw',
			'authorizationConfiguration' => $authConfig,
		];
	}//end toConsumer()
}//end class
