<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pipelinq;

use Psr\Log\LoggerInterface;

/**
 * A case hanging under a programme, and the satisfaction survey a closing case
 * asks for.
 *
 * A gemeente programme sits above the zaken: "Omgevingswet implementatie",
 * "Sloop Kerkstraat". pipelinq holds it, under the slug `programme` rather than
 * `project`, because `project` is planninq's and a schema slug is global per
 * organisation.
 *
 * 🔴 DOSSIQ DECLARES NO PROGRAMME AND NO BUDGET. Round 4 rejected the
 * budget-on-a-case row, and the capability lives in pipelinq instead. A case
 * hangs under a programme BY REFERENCE, named `dossiq:case`, and pipelinq never
 * copies the case.
 *
 * 🔴 A FIGURE WITHOUT ITS MODE IS NOT A FIGURE. Progress is rendered with the
 * mode that produced it, and a figure pipelinq says it cannot compute is
 * rendered as uncomputable. Zero would read as "nothing has been done", which
 * is a different and much worse sentence than "we cannot tell".
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
class ProgrammeConsumer {

	/**
	 * How a dossiq case names itself as a piece of work.
	 *
	 * `<app>:<schema>`, per pipelinq's work item contract.
	 */
	public const OBJECT_TYPE = 'dossiq:case';

	/**
	 * @param PipelinqGateway $gateway The only seam that names pipelinq.
	 * @param LoggerInterface $logger Says when a link did not land.
	 */
	public function __construct(
		private readonly PipelinqGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function linkCase(string $programmeId, string $caseId, string $title = ''): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'linkWork',
			arguments: [
				'programmeId' => trim($programmeId),
				'domainObjectType' => self::OBJECT_TYPE,
				'domainObjectRef' => trim($caseId),
				'title' => trim($title),
			],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return ['linked' => false, 'reason' => $answer['reason']];
		}

		$result = $answer['value'];
		if ((int)($result['status'] ?? 0) === 201) {
			return ['linked' => true, 'reason' => ''];
		}

		$reason = (string)($result['error'] ?? 'pipelinq refused the link');

		$this->logger->info(
			'Dossiq pipelinq: a case was not put under a programme, and the refusal names where it '
			. 'already is: ' . $reason,
			['case' => $caseId, 'programme' => $programmeId]
		);

		return ['linked' => false, 'reason' => $reason];
	}//end linkCase()

	/**
	 * How far along a programme is, ready to render.
	 *
	 * @param string $programmeId The programme.
	 * @param array<string, mixed> $programme The programme record, when the
	 *   caller already holds it.
	 * @param array<int, array<string, mixed>> $tasks Its tasks.
	 *
	 * @return array{available: bool, computable: bool, progress: int|null, mode: string, sentence: string}
	 *   The figure and the sentence a surface renders.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function progressOf(string $programmeId, array $programme = [], array $tasks = []): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'progressFor',
			arguments: ['programme' => ($programme + ['id' => trim($programmeId)]), 'tasks' => $tasks],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return [
				'available' => false,
				'computable' => false,
				'progress' => null,
				'mode' => '',
				'sentence' => 'Progress cannot be read on this instance.',
			];
		}

		$figure = $answer['value'];
		$mode = (string)($figure['mode'] ?? '');
		$computable = (($figure['computable'] ?? false) === true);

		if ($computable === false) {
			// NOT zero. The reason pipelinq gave is what a reader needs.
			return [
				'available' => true,
				'computable' => false,
				'progress' => null,
				'mode' => $mode,
				'sentence' => (string)($figure['reason'] ?? 'Progress cannot be computed.'),
			];
		}

		$progress = (int)($figure['progress'] ?? 0);

		return [
			'available' => true,
			'computable' => true,
			'progress' => $progress,
			'mode' => $mode,
			'sentence' => "{$progress} per cent, " . $this->modeSentence(mode: $mode),
		];
	}//end progressOf()

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
	public function caseCompleted(array $case, array $party = []): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::SURVEY_DISPATCH,
			method: 'onInteractionCompleted',
			arguments: [
				'entityType' => 'case',
				'entity' => $case,
				'contact' => $party,
			],
			fallback: null,
		);

		if ($answer['answered'] === false) {
			return ['handedOff' => false, 'reason' => $answer['reason']];
		}

		return ['handedOff' => true, 'reason' => ''];
	}//end caseCompleted()

	/**
	 * What a mode reads as, beside its figure.
	 *
	 * @param string $mode The mode pipelinq named.
	 *
	 * @return string The clause.
	 */
	private function modeSentence(string $mode): string {
		return match ($mode) {
			'manual' => 'entered by hand.',
			'fromTasks' => 'derived from the tasks that are closed.',
			'fromEffort' => 'derived from the hours booked against the estimate.',
			default => 'from a mode this instance does not name.',
		};
	}//end modeSentence()
}//end class
