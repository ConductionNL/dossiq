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
	 * The figure when pipelinq cannot answer for a programme's progress.
	 *
	 * @var array{available: bool, computable: bool, progress: null, mode: string, sentence: string}
	 */
	private const UNREADABLE_PROGRESS = [
		'available' => false,
		'computable' => false,
		'progress' => null,
		'mode' => '',
		'sentence' => 'Progress cannot be read on this instance.',
	];

	/**
	 * How a dossiq case names itself as a piece of work.
	 *
	 * `<app>:<schema>`, per pipelinq's work item contract.
	 */
	public const OBJECT_TYPE = 'dossiq:case';

	/**
	 * pipelinq's config key for its programme schema (slug `deliveryProgramme`).
	 */
	public const PROGRAMME_SCHEMA_KEY = 'programme_schema';

	/**
	 * pipelinq's config key for the work items that hold a case by reference.
	 */
	public const WORK_ITEM_SCHEMA_KEY = 'programmeWorkItem_schema';

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
		$answer = $this->askLinkWork(
			arguments: [
				'programmeId' => trim($programmeId),
				'domainObjectType' => self::OBJECT_TYPE,
				'domainObjectRef' => trim($caseId),
				'title' => trim($title),
			]
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return ['linked' => false, 'reason' => $answer['reason']];
		}

		if ((int)($answer['value']['status'] ?? 0) === 201) {
			return ['linked' => true, 'reason' => ''];
		}

		return $this->refuseLink(result: $answer['value'], where: ['case' => $caseId, 'programme' => $programmeId]);
	}//end linkCase()

	/**
	 * Ask pipelinq to put a piece of work under a programme.
	 *
	 * @param array<string, string> $arguments The programme, the work's type and reference, and its title.
	 *
	 * @return array{answered: bool, value: mixed, reason: string} The gateway's answer.
	 */
	private function askLinkWork(array $arguments): array {
		return $this->gateway->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'linkWork',
			arguments: $arguments,
			fallback: null,
		);
	}//end askLinkWork()

	/**
	 * Log and answer a link pipelinq refused, with the refusal it gave.
	 *
	 * @param array<string, mixed>  $result The refusal pipelinq answered.
	 * @param array<string, string> $where  The case and the programme, as the log context.
	 *
	 * @return array{linked: bool, reason: string} The refused outcome.
	 */
	private function refuseLink(array $result, array $where): array {
		$reason = (string)($result['error'] ?? 'pipelinq refused the link');

		$this->logger->info(
			'Dossiq pipelinq: a case was not put under a programme, and the refusal names where it '
			. 'already is: ' . $reason,
			$where
		);

		return ['linked' => false, 'reason' => $reason];
	}//end refuseLink()

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
			return self::UNREADABLE_PROGRESS;
		}

		return $this->figureOf(figure: $answer['value']);
	}//end progressOf()

	/**
	 * Render the figure pipelinq answered, computable or not.
	 *
	 * @param array<string, mixed> $figure The figure pipelinq answered.
	 *
	 * @return array{available: bool, computable: bool, progress: int|null, mode: string, sentence: string}
	 *   The figure and its sentence.
	 */
	private function figureOf(array $figure): array {
		if (($figure['computable'] ?? false) !== true) {
			// NOT zero. The reason pipelinq gave is what a reader needs.
			return self::uncomputableFigure(figure: $figure);
		}

		return $this->computableFigure(figure: $figure);
	}//end figureOf()

	/**
	 * A figure pipelinq says it cannot compute, with pipelinq's reason.
	 *
	 * @param array<string, mixed> $figure The figure pipelinq answered.
	 *
	 * @return array{available: bool, computable: bool, progress: null, mode: string, sentence: string}
	 *   The uncomputable figure.
	 */
	private static function uncomputableFigure(array $figure): array {
		return [
			'available' => true,
			'computable' => false,
			'progress' => null,
			'mode' => (string)($figure['mode'] ?? ''),
			'sentence' => (string)($figure['reason'] ?? 'Progress cannot be computed.'),
		];
	}//end uncomputableFigure()

	/**
	 * A computed figure, with the sentence that names its mode.
	 *
	 * @param array<string, mixed> $figure The figure pipelinq answered.
	 *
	 * @return array{available: bool, computable: bool, progress: int, mode: string, sentence: string}
	 *   The computed figure.
	 */
	private function computableFigure(array $figure): array {
		$mode = (string)($figure['mode'] ?? '');
		$progress = (int)($figure['progress'] ?? 0);

		return [
			'available' => true,
			'computable' => true,
			'progress' => $progress,
			'mode' => $mode,
			'sentence' => "{$progress} per cent, " . $this->modeSentence(mode: $mode),
		];
	}//end computableFigure()

	/**
	 * The programmes a case may be put under, as id and name.
	 *
	 * @return array{available: bool, programmes: array<int, array{id: string, name: string}>}
	 *   `available` false means pipelinq did not answer, which is not the same
	 *   as an instance that has no programmes.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function programmes(): array {
		$rows = $this->read(schemaKey: self::PROGRAMME_SCHEMA_KEY, filters: []);
		if ($rows === null) {
			return ['available' => false, 'programmes' => []];
		}

		$programmes = [];
		foreach ($rows as $row) {
			$id = self::idOf(row: $row);
			if ($id === '') {
				// A row with no id cannot be linked to, so it is not offered.
				continue;
			}

			$programmes[] = ['id' => $id, 'name' => trim((string)($row['name'] ?? ''))];
		}

		return ['available' => true, 'programmes' => $programmes];
	}//end programmes()

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
	public function programmeOf(string $caseId): array {
		$items = $this->workItemsOf(caseId: $caseId);
		if ($items === null) {
			return ['available' => false, 'programme' => null];
		}

		$holder = self::holderOf(items: $items);
		if ($holder === '') {
			return ['available' => true, 'programme' => null];
		}

		return ['available' => true, 'programme' => $this->describeProgramme(holder: $holder)];
	}//end programmeOf()

	/**
	 * The work items that hold a case by reference, or null when pipelinq cannot answer.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>>|null The work items.
	 */
	private function workItemsOf(string $caseId): ?array {
		return $this->read(
			schemaKey: self::WORK_ITEM_SCHEMA_KEY,
			filters: ['domainObjectType' => self::OBJECT_TYPE, 'domainObjectRef' => trim($caseId)],
		);
	}//end workItemsOf()

	/**
	 * The programme the first work item that names one hangs under.
	 *
	 * @param array<int, array<string, mixed>> $items The work items.
	 *
	 * @return string The programme id, '' when none names one.
	 */
	private static function holderOf(array $items): string {
		$holder = '';
		foreach ($items as $item) {
			$holder = trim((string)($item['programme'] ?? ''));
			if ($holder !== '') {
				break;
			}
		}

		return $holder;
	}//end holderOf()

	/**
	 * A programme by id, name and progress.
	 *
	 * @param string $holder The programme id.
	 *
	 * @return array{id: string, name: string, progress: array<string, mixed>} The programme.
	 */
	private function describeProgramme(string $holder): array {
		$record = $this->programmeRecord(holder: $holder);

		return [
			'id' => $holder,
			'name' => trim((string)($record['name'] ?? '')),
			'progress' => $this->progressOf(programmeId: $holder, programme: $record, tasks: $this->tasksOf(programmeId: $holder)),
		];
	}//end describeProgramme()

	/**
	 * The stored programme record, or just its id when the list does not hold it.
	 *
	 * @param string $holder The programme id.
	 *
	 * @return array<string, mixed> The record, always carrying `id`.
	 */
	private function programmeRecord(string $holder): array {
		foreach (($this->read(schemaKey: self::PROGRAMME_SCHEMA_KEY, filters: []) ?? []) as $row) {
			if (self::idOf(row: $row) === $holder) {
				return $row + ['id' => $holder];
			}
		}

		return ['id' => $holder];
	}//end programmeRecord()

	/**
	 * The tasks of a programme, the rows that are not arrays left out.
	 *
	 * @param string $programmeId The programme.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 */
	private function tasksOf(string $programmeId): array {
		$tasks = $this->gateway->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'tasksOf',
			arguments: ['programmeId' => $programmeId],
			fallback: [],
		);

		if (is_array($tasks['value']) === false) {
			return [];
		}

		return array_values(array_filter($tasks['value'], static fn ($task): bool => is_array($task) === true));
	}//end tasksOf()

	/**
	 * Read rows of one of pipelinq's programme schemas, or null when it cannot answer.
	 *
	 * @param string $schemaKey The schema config key in pipelinq.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function read(string $schemaKey, array $filters): ?array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'read',
			arguments: ['schemaKey' => $schemaKey, 'filters' => $filters],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return null;
		}

		return array_values(array_filter($answer['value'], static fn ($row): bool => is_array($row) === true));
	}//end read()

	/**
	 * The id of a pipelinq row, whichever key carries it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, '' when it has none.
	 */
	private static function idOf(array $row): string {
		return trim((string)($row['id'] ?? $row['uuid'] ?? ''));
	}//end idOf()

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
