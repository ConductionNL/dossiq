<?php

/**
 * Dossiq federated transfer idempotency.
 *
 * A federated hand-off may be asked for twice: the remote retries, or an
 * operator presses the button again. The second ask must answer the transfer
 * that already exists rather than open another one, because two pending
 * transfers of one case to one organisation is a case that can be accepted
 * twice.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseTransferService}, which was at
 * its complexity ceiling: the key, the lookup and the federation leaf it needs
 * are one concern and nothing else in that class reads them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

/**
 * The key a federated hand-off is recognised by, and the transfer it finds.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
 */
class FederatedIdempotency {
	/**
	 * Constructor.
	 *
	 * @param TransferRegisterGateway $gateway OpenRegister resolution for the transfer surface.
	 */
	public function __construct(
		private readonly TransferRegisterGateway $gateway,
	) {
	}//end __construct()

	/**
	 * The idempotency key a federated hand-off needs, and the answer when there is one already.
	 *
	 * A hand-off that has already been initiated for this case, target and
	 * remote is answered with the transfer that exists rather than a second
	 * one. A local hand-off needs no key and has nothing to look up.
	 *
	 * @param string|null $remoteCloudId      The remote, or null for a local hand-off.
	 * @param string      $caseId             The case.
	 * @param string      $targetOrganization Who is receiving it.
	 * @param int         $register           The register transfers live in.
	 * @param int         $schema             The transfer schema.
	 * @param object      $objectService      The OpenRegister object service.
	 *
	 * @return array{key: string|null, answer: array<string, mixed>|null} The key, and the
	 *         answer to give straight back when there is one.
	 */
	public function precheck(
		?string $remoteCloudId,
		string $caseId,
		string $targetOrganization,
		int $register,
		int $schema,
		object $objectService,
	): array {
		if ($remoteCloudId === null || $remoteCloudId === '') {
			return ['key' => null, 'answer' => null];
		}

		if ($this->gateway->federationShareService() === null) {
			return [
				'key' => null,
				'answer' => ['error' => 'Federated case transfer requires the OpenRegister federation leaf'],
			];
		}

		$idempotencyKey = hash('sha256', $caseId . '|' . $targetOrganization . '|' . $remoteCloudId);

		return [
			'key' => $idempotencyKey,
			'answer' => $this->findByKey(
				idempotencyKey: $idempotencyKey,
				register: $register,
				schema: $schema,
				objectService: $objectService,
			),
		];
	}//end precheck()

	/**
	 * Find an existing transfer by idempotency key (pending or accepted
	 * only — a rejected transfer does not block re-initiating).
	 *
	 * @param string $idempotencyKey The sha256 idempotency key
	 * @param int $register The register id
	 * @param int $schema The schema id
	 * @param object $objectService The resolved OR ObjectService
	 *
	 * @return array|null The existing transfer data, or null when none found
	 */
	public function findByKey(string $idempotencyKey, int $register, int $schema, object $objectService): ?array {
		try {
			$matches = $objectService->findAll(
				['filters' => ['register' => $register, 'schema' => $schema, 'idempotencyKey' => $idempotencyKey]],
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((array)$matches as $match) {
			$matchData = $match;
			if (is_array($match) === false) {
				$matchData = $match->jsonSerialize();
			}

			$status = (string)($matchData['status'] ?? '');
			if ($status === 'pending' || $status === 'accepted') {
				return $matchData;
			}
		}

		return null;
	}//end findByKey()
}//end class
