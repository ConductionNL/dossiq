<?php

/**
 * Contract of PipelinqGateway
 *
 * The public surface of PipelinqGateway that other components call, so they depend
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
 * Contract of PipelinqGateway.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PipelinqGatewayInterface {

	/**
	 * Whether this instance has pipelinq at all.
	 *
	 * Asked by a surface deciding between "nothing to show" and "pipelinq is
	 * not installed". The contact moments leaf is the probe because every
	 * capability here postdates it: an instance carrying it carries the rest.
	 *
	 * @return bool True when pipelinq's services can be resolved.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
	 */
	public function isAvailable(): bool;

	/**
	 * The open tickets of the pipelinq clients that share each master entity.
	 *
	 * Read through pipelinq's ticket leaf as the calling user, like every
	 * other read on this seam. Null when pipelinq is absent, predates the
	 * leaf, or has no ticket surface configured: the caller then says
	 * "unavailable" instead of drawing zero tickets.
	 *
	 * @param array<int, string> $refs The master entity references.
	 *
	 * @return array<string, array<int, array<string, mixed>>>|null Tickets per reference, or null.
	 *
	 * @spec openspec/specs/pipelinq-consumption/spec.md
	 */
	public function openTicketsByMasterEntity(array $refs): ?array;

	/**
	 * The tickets of the pipelinq clients that share each master entity, by
	 * status: `open` reads as openTicketsByMasterEntity(), `closed` and `all`
	 * also list the handled ones. Null when pipelinq is absent, predates the
	 * status filter, or has no ticket surface configured.
	 *
	 * @param array<int, string> $refs The master entity references.
	 * @param string $status `open`, `closed` or `all`.
	 *
	 * @return array<string, array<int, array<string, mixed>>>|null Tickets per reference, or null.
	 *
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	public function ticketsByMasterEntity(array $refs, string $status): ?array;
}//end interface
