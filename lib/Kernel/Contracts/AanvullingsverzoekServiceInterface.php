<?php

/**
 * Contract of AanvullingsverzoekService
 *
 * The public surface of AanvullingsverzoekService that other components call, so they depend
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

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of AanvullingsverzoekService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AanvullingsverzoekServiceInterface {

	/**
	 * The schema the requests are objects of.
	 */
	public const SCHEMA = 'aanvullingsverzoek';

	/**
	 * A request to make a Woo request more precise (Woo art. 4.1 lid 5).
	 */
	public const KIND_CLARIFICATION = 'verduidelijking';

	/**
	 * Ask the applicant for what is missing, and write the request down.
	 *
	 * @param string             $caseId       The case UUID.
	 * @param array<int, string> $items        What is missing, one line each.
	 * @param string             $recipient    Who to send it to.
	 * @param integer            $durationDays How long the applicant is given.
	 * @param string             $userId       Who is asking.
	 * @param string             $pauseReason  The administered reason that types it.
	 * @param string             $rationale    Why the case cannot be decided yet.
	 * @param string             $party        The party being asked.
	 * @param string|null        $question     The one question of a `verduidelijking` (Woo only), as the
	 *                                         requester reads it; null asks for documents.
	 *
	 * @return array<string, mixed> The written request.
	 *
	 * @throws RefusedException When the act refuses, or the record cannot be written.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function ask(
		string $caseId,
		array $items,
		string $recipient,
		int $durationDays,
		string $userId,
		string $pauseReason = '',
		string $rationale = '',
		string $party = '',
		?string $question = null,
	): array;

	/**
	 * How long a request has been open, in days.
	 *
	 * @param array<string, mixed>   $request The request.
	 * @param DateTimeImmutable|null $now     Today.
	 *
	 * @return integer The days, 0 when the moment cannot be read.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function daysOpen(array $request, ?DateTimeImmutable $now = null): int;

	/**
	 * Every request ever sent on a case, newest last.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function forCase(string $caseId): array;

	/**
	 * The open request on a case, or null when nothing is outstanding.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The request, or null.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function openFor(string $caseId): ?array;

	/**
	 * Write a request, new or changed.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string               $id      Its id, or the empty string to create.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RefusedException When the write fails.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function write(array $request, string $id = ''): array;
}//end interface
