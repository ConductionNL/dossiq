<?php

/**
 * Contract of LeaverHandoverService
 *
 * The public surface of LeaverHandoverService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of LeaverHandoverService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaverHandoverServiceInterface {

	/**
	 * Hand everything the leaver holds to another person, and record it.
	 *
	 * @param string $fromUser The person who is leaving.
	 * @param string $toUser   The person receiving the work.
	 * @param string $actor    The administrator running the act.
	 *
	 * @return array{batchId: string, job: array<string, mixed>, succeeded: int, failed: int,
	 *               coordinatorSeats: int, drafts: array<int, array<string, mixed>>,
	 *               tasks: array<int, array<string, mixed>>}
	 *         What moved, and the job that is moving the cases.
	 *
	 * @throws InvalidArgumentException When a person is missing, or is their own successor.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function execute(string $fromUser, string $toUser, string $actor): array;

	/**
	 * What a leaver handover would move, and how much of it. Mutates nothing.
	 *
	 * @param string $fromUser The person who is leaving.
	 *
	 * @return array{cases: array<int, array<string, mixed>>, coordinatorCases: array<int, string>,
	 *               tasks: array<int, array<string, mixed>>, drafts: array<int, array<string, mixed>>,
	 *               counts: array<string, int>}
	 *         The preview.
	 *
	 * @throws InvalidArgumentException When no person is named.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function preview(string $fromUser): array;
}//end interface
