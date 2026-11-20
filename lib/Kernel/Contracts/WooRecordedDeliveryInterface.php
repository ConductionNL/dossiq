<?php

/**
 * Contract of WooDeliveredSetWriter
 *
 * The public surface of WooDeliveredSetWriter that other components call, so they depend
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

use RuntimeException;
use Throwable;

/**
 * Contract of WooDeliveredSetWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooRecordedDeliveryInterface {

	/**
	 * The failure a delivery answers when its set cannot be written first.
	 */
	public const SET_NOT_WRITTEN = 'woo_delivered_set_not_written';

	/**
	 * Write the pending set, send, then freeze it; delete it when the send fails.
	 *
	 * @param string                           $caseId     The Woo case.
	 * @param string                           $decisionId The Woo decision.
	 * @param array<int, array<string, mixed>> $delivered  What goes out (see open()).
	 * @param callable(): string               $send       Creates or updates the publication; answers its id.
	 *
	 * @return array{publicationId: string, setId: string}
	 *
	 * @throws RuntimeException SET_NOT_WRITTEN when the set cannot be written; nothing is sent then.
	 * @throws Throwable        The send's own failure, after the pending set is deleted.
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function deliver(string $caseId, string $decisionId, array $delivered, callable $send): array;

	/**
	 * Stamp `withdrawnAt` on the frozen set of a publication; the set stays frozen.
	 *
	 * @param string $caseId        The case.
	 * @param string $publicationId The withdrawn publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function markWithdrawn(string $caseId, string $publicationId): void;
}//end interface
