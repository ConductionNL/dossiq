<?php

/**
 * Contract of CaseCollaborationService
 *
 * The public surface of CaseCollaborationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseCollaborationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCollaborationServiceInterface {

	/**
	 * List the activity entries for a federated share (local, session
	 * already authorised by the controller).
	 *
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return array The activity entries (empty array when none/unavailable)
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
	 */
	public function listActivity(string $federatedShareId): array;

	/**
	 * List the activity entries for a federated share via a remote bearer
	 * token (same resolution guard as {@see postRemoteActivity()}).
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return array The activity entries, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
	 */
	public function listRemoteActivity(string $shareToken, string $federatedShareId): array;

	/**
	 * Post a local (session-authenticated) activity entry. The caller MUST
	 * already have authorised the request against the share's caseId
	 * (ADR-005) before calling this — this method trusts $actorUserId.
	 *
	 * @param string $federatedShareId The caseFederatedShare UUID
	 * @param string $actorUserId The posting user's NC user id
	 * @param string $message The activity message
	 *
	 * @return array The updated activity stream, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
	 */
	public function postLocalActivity(string $federatedShareId, string $actorUserId, string $message): array;

	/**
	 * Post a remote activity entry, authenticated via the federated share's
	 * scoped bearer token. Fails closed on any resolution mismatch: unknown
	 * token, revoked/declined share, wrong direction, or a token minted for
	 * a DIFFERENT federated share.
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $federatedShareId The caseFederatedShare UUID the caller claims to post to
	 * @param string $message The activity message
	 *
	 * @return array The updated activity stream, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-posts-an-activity-entry-via-its-scoped-token
	 */
	public function postRemoteActivity(string $shareToken, string $federatedShareId, string $message): array;
}//end interface
