<?php

/**
 * Contract of ProgrammeConsumer
 *
 * The public surface of ProgrammeConsumer that other components call, so they depend
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
 * Contract of ProgrammeConsumer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammeConsumerInterface {

	/**
	 * Tell pipelinq that a case reached a terminal status.
	 *
	 * Dossiq holds no survey, invitation, token, cooldown or opt-out, and makes
	 * no decision about sending. It says the interaction completed; pipelinq's
	 * dispatch rules decide the rest.
	 *
	 * Never blocks the case's own save: a case closing is the handler's work.
	 *
	 * @param array<string, mixed> $case The case, as saved.
	 * @param array<string, mixed> $party The party to ask, when one is known.
	 *
	 * @return array{handedOff: bool, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-closing-case-asks-pipelinq-for-the-satisfaction-survey-and-holds-no-survey-engine-req-plq-07
	 */
	public function caseCompleted(array $case, array $party = []): array;

	/**
	 * Hang a case under a programme.
	 *
	 * @param string $programmeId The programme.
	 * @param string $caseId The case.
	 * @param string $title The case's title as it reads now, so the item stays
	 *   legible on an instance that later loses dossiq.
	 *
	 * @return array{linked: bool, reason: string} The outcome, with the
	 *   refusal naming the programme that already holds the case.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function linkCase(string $programmeId, string $caseId, string $title = ''): array;

	/**
	 * The programme a case hangs under, with its progress, or null.
	 *
	 * The case is found BY REFERENCE: the work item pipelinq holds names it as
	 * `dossiq:case` and its uuid. Dossiq copies nothing of the programme.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array{available: bool, programme: array{id: string, name: string, progress: array<string, mixed>}|null}
	 *   The programme and its figure; `programme` null with `available` true is
	 *   a case under no programme.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function programmeOf(string $caseId): array;

	/**
	 * The programmes a case may be put under, as id and name.
	 *
	 * @return array{available: bool, programmes: array<int, array{id: string, name: string}>}
	 *   `available` false means pipelinq did not answer, which is not the same
	 *   as an instance that has no programmes.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function programmes(): array;
}//end interface
