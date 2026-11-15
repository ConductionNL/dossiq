<?php

/**
 * Contract of FileRequestService
 *
 * The public surface of FileRequestService that other components call, so they depend
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

/**
 * Contract of FileRequestService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FileRequestServiceInterface {

	/**
	 * Ask one party of a case for a file.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $personUid The party's uid, as the case's people list names them.
	 * @param string $note What the party is asked for.
	 * @param int $days How long the request stands, 0 for the default.
	 *
	 * @return array<string, mixed> The recipient, the token and when it expires.
	 *
	 * @throws RuntimeException 404 when the person is not on the case, 403 when an
	 *         indicator on them refuses the send, 422 when they have no address or
	 *         the case has no folder.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function request(string $caseId, string $personUid, string $note = '', int $days = 0): array;
}//end interface
