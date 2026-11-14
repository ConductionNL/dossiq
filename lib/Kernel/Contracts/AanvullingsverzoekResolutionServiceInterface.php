<?php

/**
 * Contract of AanvullingsverzoekResolutionService
 *
 * The public surface of AanvullingsverzoekResolutionService that other components call, so they depend
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
 * Contract of AanvullingsverzoekResolutionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AanvullingsverzoekResolutionServiceInterface {

	/**
	 * Expire the open request on a case, leaving everything else as it was.
	 *
	 * Writes ONE field. The items, the dates and the reason stay exactly as
	 * they were sent, because they are the evidence that the applicant was
	 * given the chance.
	 *
	 * @param string                 $caseId The case UUID.
	 * @param DateTimeImmutable|null $now    Today.
	 *
	 * @return array<string, mixed>|null The expired request, or null when there
	 *                                   was nothing open or it has not run out.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function expireIfRunOut(string $caseId, ?DateTimeImmutable $now = null): ?array;

	/**
	 * Record what arrived, and close the request when the handler says so.
	 *
	 * @param string                 $caseId   The case UUID.
	 * @param array<int, string>     $received The items that arrived, by their text.
	 * @param boolean                $complete Whether the handler says the
	 *                                         request is now answered in full.
	 * @param string                 $userId   Who recorded it.
	 * @param DateTimeImmutable|null $when     When it arrived.
	 *
	 * @return array<string, mixed> The request as it now stands.
	 *
	 * @throws RefusedException When nothing is open, or completion is claimed
	 *                          with items still outstanding.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function recordAnswer(
		string $caseId,
		array $received,
		bool $complete,
		string $userId,
		?DateTimeImmutable $when = null,
	): array;
}//end interface
