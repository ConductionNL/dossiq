<?php

/**
 * Contract of CaseTakeoverRequest
 *
 * The public surface of CaseTakeoverRequest that other components call, so they depend
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
 * Contract of CaseTakeoverRequest.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTakeoverRequestInterface {

	/**
	 * The holder accepts: the case moves to the asker and a holding opens.
	 *
	 * @param string $takeoverId The request's uuid.
	 * @param string $acceptedBy Who answered.
	 * @param string $caseId     The case the caller was authorised on; the request must be on it.
	 *
	 * @return array<string, mixed> The answered request.
	 *
	 * @throws RefusedException When the request is unknown, on another case, or already answered.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function accept(string $takeoverId, string $acceptedBy, string $caseId): array;

	/**
	 * Every request on a case, newest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function onCase(string $caseId): array;

	/**
	 * The holder refuses: the case stays, and the reason is on the record.
	 *
	 * @param string $takeoverId The request's uuid.
	 * @param string $reason     Why the holder is keeping the case.
	 * @param string $refusedBy  Who answered.
	 * @param string $caseId     The case the caller was authorised on; the request must be on it.
	 *
	 * @return array<string, mixed> The answered request.
	 *
	 * @throws RefusedException When the request is unknown, on another case, already answered, or the reason is missing.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function refuse(string $takeoverId, string $reason, string $refusedBy, string $caseId): array;

	/**
	 * Ask the holder for a case.
	 *
	 * @param string $caseId      The case being asked for.
	 * @param string $requestedBy Who wants it.
	 * @param string $reason      Why they should have it.
	 *
	 * @return array<string, mixed> The request as stored.
	 *
	 * @throws RefusedException When the case cannot be read, the asker already holds it, or the record cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function request(string $caseId, string $requestedBy, string $reason): array;
}//end interface
