<?php

/**
 * Contract of AcknowledgementService
 *
 * The public surface of AcknowledgementService that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of AcknowledgementService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AcknowledgementServiceInterface {

	/**
	 * Confirm receipt of this case, once.
	 *
	 * Sending twice is worse than sending late: the citizen reads the second
	 * one as a second case. So a case whose duty already reads met returns the
	 * record it already has and sends nothing.
	 *
	 * @param string  $caseId  The case UUID.
	 * @param integer $attempt Which attempt this is, counting from one.
	 *
	 * @return array<string, mixed> `{sent: bool, reason?: string, duty: array, record?: array}`.
	 *
	 * @throws RefusedException When the case carries no address, or no transport took the acknowledgement.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function acknowledge(string $caseId, int $attempt = 1): array;

	/**
	 * The duty as the case carries it.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The duty, empty when the case cannot be read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function dutyFor(string $caseId): array;

	/**
	 * Record that an attempt failed, and whether any are left.
	 *
	 * The duty stays pending while a retry is still coming and reads unmet once
	 * they are spent, because at that point a person has to perform it by hand
	 * and needs to be able to find the case. Neither state is a log line.
	 *
	 * @param string  $caseId   The case UUID.
	 * @param string  $sentence What went wrong, as one sentence.
	 * @param integer $attempt  Which attempt failed, counting from one.
	 *
	 * @return boolean TRUE when another attempt is due.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function recordFailedAttempt(string $caseId, string $sentence, int $attempt): bool;

	/**
	 * Record that a person confirmed receipt another way.
	 *
	 * A duty met by post is met. What the case has to carry is who said so and
	 * when, because an auditor asking "did we confirm receipt" is entitled to
	 * an answer that names a person rather than a green tick.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $how    How receipt was confirmed, in the recorder's words.
	 * @param string $recordedBy The user id of the person recording it.
	 *
	 * @return array<string, mixed> The duty as it now reads.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function recordMetAnotherWay(string $caseId, string $how, string $recordedBy): array;
}//end interface
