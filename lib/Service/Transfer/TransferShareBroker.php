<?php

/**
 * Procest transfer-scoped federated share broker.
 *
 * Owns both ends of the token that lets a remote org accept or reject ONE
 * zaakoverdracht: minting it when a federated transfer is initiated, and
 * resolving it back to that transfer when the remote org calls the
 * `#[PublicPage]` accept/reject endpoint.
 *
 * The security property this class exists to hold is scope confinement. A
 * transfer token is minted `scope: object`, `permissions: read-write`, pointed
 * at ONLY the transfer object — it grants no access to the case itself. On the
 * way back in, four independent conditions must all hold before a token is
 * accepted: the share must be OUTGOING, not revoked/declined, read-write, and
 * its objectUri tail must equal the exact transfer id being acted on. That
 * last check is what stops a read-only case-summary token, or a token minted
 * for a different transfer, from authenticating this one.
 *
 * Every failure — unavailable leaf, unknown token, wrong direction, wrong
 * permissions, wrong object — returns null. There is no partial grant.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#a-read-only-case-share-token-cannot-accept-a-transfer
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transfer;

use Psr\Log\LoggerInterface;

/**
 * Mints and resolves the transfer-scoped OCM share token.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#a-read-only-case-share-token-cannot-accept-a-transfer
 */
class TransferShareBroker {
	/**
	 * Constructor.
	 *
	 * @param TransferRegisterGateway $gateway OpenRegister resolution for the transfer surface
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly TransferRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mint a transfer-scoped OR federated share (read-write, pointed only
	 * at the transfer object) so the remote org can later authenticate its
	 * accept/reject call. Distinct from the case-summary share's token —
	 * this one grants no access to the case itself, only to this one
	 * transfer's status field via procest's own state machine.
	 *
	 * @param string $transferUuid The transfer object's uuid
	 * @param string $remoteCloudId The federated target (slug@host)
	 * @param string $register The register id/slug
	 * @param string $schema The case_transfer_schema id/slug
	 *
	 * @return object|null The minted OR FederatedShare, or null on failure
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function mintTransferShare(string $transferUuid, string $remoteCloudId, string $register, string $schema): ?object {
		$shareService = $this->gateway->federationShareService();
		if ($shareService === null) {
			return null;
		}

		try {
			return $shareService->createOutgoingShare(
				params: [
					'scope' => 'object',
					'register' => $register,
					'schema' => $schema,
					'objectUri' => $transferUuid,
					'sharedWith' => $remoteCloudId,
					'permissions' => 'read-write',
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseTransferService: OR createOutgoingShare failed',
				['transferUuid' => $transferUuid, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end mintTransferShare()

	/**
	 * Resolve a scoped bearer token to the transfer it authorises — used
	 * exclusively by the `#[PublicPage]` remote accept/reject endpoint.
	 * Requires an OUTGOING, read-write, non-revoked/declined OR
	 * FederatedShare whose objectUri tail matches this exact transfer id,
	 * so a token minted for one transfer (or for a read-only case-summary
	 * share) can never authenticate a different transfer.
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $transferId The candidate transfer UUID
	 *
	 * @return array{sharedWith: string, organisation: ?string}|null The resolved grant, or null when invalid
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-read-only-case-share-token-cannot-accept-a-transfer
	 */
	public function resolveTransferShare(string $shareToken, string $transferId): ?array {
		$shareMapper = $this->gateway->federatedShareMapper();
		if ($shareMapper === null || $shareToken === '' || $transferId === '') {
			return null;
		}

		try {
			$share = $shareMapper->findByToken($shareToken);
		} catch (\Throwable $e) {
			return null;
		}

		if ($share->getDirection() !== 'outgoing') {
			return null;
		}

		if (in_array($share->getStatus(), ['revoked', 'declined'], true) === true) {
			return null;
		}

		if ($share->getPermissions() !== 'read-write') {
			return null;
		}

		$objectUri = (string)$share->getObjectUri();
		if ($this->uuidFromUri(uri: $objectUri) !== $transferId) {
			return null;
		}

		return [
			'sharedWith' => (string)$share->getSharedWith(),
			'organisation' => $share->getOrganisation(),
		];
	}//end resolveTransferShare()

	/**
	 * Extract the trailing uuid from a canonical object uri (or return it
	 * as-is when it is already a bare uuid).
	 *
	 * @param string $uri The object uri or uuid
	 *
	 * @return string The uuid
	 */
	private function uuidFromUri(string $uri): string {
		$parts = explode('/', rtrim($uri, '/'));
		return (string)end($parts);
	}//end uuidFromUri()
}//end class
