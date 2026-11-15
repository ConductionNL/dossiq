<?php

/**
 * Dossiq applicatie directory
 *
 * Finds the OpenRegister consumers an applicatie list shows, and reads the
 * client_id a caller's bearer token names.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * Looks applicaties up among OpenRegister's consumers.
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */
final class ApplicatieDirectory {
	/**
	 * Constructor.
	 *
	 * @param object|null $consumerMapper OpenRegister's consumer mapper, null when OpenRegister is absent.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly ?object $consumerMapper,
	) {
	}//end __construct()

	/**
	 * Read the clientId the list is filtered on.
	 *
	 * Both query parameters are read; 'clientId' wins over 'clientIds' and an
	 * empty value counts as absent.
	 *
	 * @param mixed $clientId  The `clientId` query parameter.
	 * @param mixed $clientIds The `clientIds` query parameter.
	 *
	 * @return mixed The requested clientId, or null when the list is unfiltered
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requestedClientId(mixed $clientId, mixed $clientIds): mixed {
		if ($clientId !== null && $clientId !== '') {
			return $clientId;
		}

		if ($clientIds !== null && $clientIds !== '') {
			return $clientIds;
		}

		return null;
	}//end requestedClientId()

	/**
	 * Read the client_id out of the token the caller presented.
	 *
	 * Only for a refusal message. Nothing here is trusted: the signature is
	 * verified before this runs, and a token this cannot read yields
	 * "onbekend" rather than an error.
	 *
	 * @param string $presentedToken The bearer token, without its scheme
	 *
	 * @return string The client_id, or 'onbekend' when it cannot be read
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function clientIdOf(string $presentedToken): string {
		$parts = explode('.', $presentedToken);
		if (count($parts) !== 3) {
			return 'onbekend';
		}

		$payload = json_decode((string)base64_decode($parts[1], true), true);
		if (is_array($payload) === false) {
			return 'onbekend';
		}

		$clientId = ($payload['client_id'] ?? ($payload['iss'] ?? null));

		if (is_string($clientId) === false || $clientId === '') {
			return 'onbekend';
		}

		return $clientId;
	}//end clientIdOf()

	/**
	 * Find the consumers the list shows.
	 *
	 * Without a filter every consumer is listed. With one, the primary clientId
	 * (the consumer name) is searched first, and only when nothing matches are
	 * the extra clientIds stored in the authorization configuration searched.
	 *
	 * @param mixed $filterClientId The requested clientId, or null
	 *
	 * @return array The matching consumers
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function listConsumers(mixed $filterClientId): array {
		$consumers = $this->consumerMapper->findAll();

		if ($filterClientId === null) {
			return $consumers;
		}

		// Search by name (primary clientId) first.
		$consumers = $this->consumerMapper->findAll(
			filters: ['name' => $filterClientId]
		);

		if (count($consumers) > 0) {
			return $consumers;
		}

		return $this->consumersWithExtraClientId(clientId: $filterClientId);
	}//end listConsumers()

	/**
	 * Find the consumers holding a clientId among their extra clientIds.
	 *
	 * @param mixed $clientId The clientId searched for
	 *
	 * @return array The consumers that carry it in their authorization configuration
	 */
	private function consumersWithExtraClientId(mixed $clientId): array {
		$consumers = [];
		$allConsumers = $this->consumerMapper->findAll();
		foreach ($allConsumers as $consumer) {
			$authConfig = $consumer->getAuthorizationConfiguration() ?? [];
			$extraClientIds = $authConfig['clientIds'] ?? [];
			if (in_array($clientId, $extraClientIds, true) === true) {
				$consumers[] = $consumer;
			}
		}

		return $consumers;
	}//end consumersWithExtraClientId()
}//end class
