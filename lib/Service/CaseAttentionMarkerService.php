<?php

/**
 * Dossiq case attention marker service.
 *
 * REQ-MRK-03: a marker declares the panel it points at, the condition that
 * raises it and the condition that clears it. The system raises it, it is not
 * per user, and it does not clear because somebody opened the panel.
 *
 * THE PAIR IS THE DECLARATION. Every marker is the condition that raises it
 * and the condition that clears it, together. Written as code that pair ends
 * up in two files and drifts; declared together on the case type an
 * administrator can read it and this class can check it (design D-6). A
 * declaration carrying a raise condition and no clear condition is refused,
 * naming the marker, which is the scenario `CaseAttentionMarkerTest` pins.
 *
 * CLEARING IS DOING THE WORK, NOT OPENING THE PANEL, and that is the whole
 * difference from the per-user unread badge of `unread-state-on-the-case`
 * (design D-8). A failed virus scan is still a failed virus scan after
 * somebody has looked at it. So the marker set is DERIVED from the conditions
 * on every save rather than stored as something a person dismisses: a marker
 * whose condition stopped being true is simply not in the next answer, and
 * nobody had to clear it.
 *
 * The panel a marker points at is named from the list the case page already
 * uses, so nothing invents a second vocabulary of panels (design D-7).
 *
 * 🔴 A CONDITION READS EITHER THE CASE OR ITS RELATED ROWS, AND THE TWO ARE
 * NOT THE SAME KIND OF READ. `caseDocuments`, `adviceRequests` and `roles` are
 * NOT properties of a case: each is a separate register object pointing back
 * at it, exactly as `37-unread-state.json` records for notes and
 * contactmomenten. A condition written against `$case['adviceRequests']` would
 * therefore be false on every case for ever, and nothing would fail. So the
 * related rows arrive as an explicit `$context`, fetched by `contextFor()`,
 * and a condition whose rows are ABSENT from the context is not evaluated at
 * all. A marker already standing for such a condition is KEPT rather than
 * cleared, because a save that could not see the rows has learnt nothing about
 * whether the work was done, and silently dropping the marker would be the
 * same no-op wearing different clothes.
 *
 * WHAT IS NOT DECLARED HERE IS AS DELIBERATE AS WHAT IS. A marker for a
 * document that failed its virus scan needs `scanVerdict`, which is the open
 * `scan-verdict-on-the-row` change and does not exist on `caseDocument` yet. A
 * marker for a party whose post came back needs a field `role` does not carry.
 * Declaring either now would put a condition in the vocabulary that nothing
 * can answer, which is the exact defect the closed list exists to prevent.
 * They land when the fields do.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What raises a marker on a case, and what makes it go away.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseAttentionMarkerService {

	use SearchesObjects;

	/**
	 * The panels of the case page a marker may point at.
	 *
	 * The ids the `case-panels` strip in `src/manifest.json` already uses. A
	 * marker pointing at a panel that is not on the page sends a handler
	 * looking for a tab that does not exist, so the list is closed.
	 *
	 * @var array<int, string>
	 */
	public const PANELS = [
		'case-data-panel',
		'case-files',
		'case-notes-panel',
		'case-people-panel',
		'case-communication-panel',
		'case-email-panel',
		'case-work-panel',
		'case-decisions-panel',
		'case-related-panel',
	];

	/**
	 * The conditions this app can actually evaluate.
	 *
	 * A closed list, because a declared condition nothing evaluates is a
	 * marker that never appears and never says why. Adding one here without
	 * adding its evaluation below is caught by `CaseAttentionMarkerTest`.
	 *
	 * @var array<int, string>
	 */
	public const RAISE_CONDITIONS = [
		'advice-request-overdue',
		'aanvullingsverzoek-open',
		'term-exceeded',
	];

	/**
	 * The context key each condition reads, for the conditions that need one.
	 *
	 * A condition absent from this map reads the case payload and can always be
	 * answered. A condition present in it can only be answered when its rows
	 * were fetched, and is skipped, with any standing marker kept, when they
	 * were not.
	 *
	 * @var array<string, string>
	 */
	public const CONDITION_CONTEXT = [
		'advice-request-overdue' => 'adviceRequests',
	];

	/**
	 * The only clearing a marker may declare.
	 *
	 * There is exactly one, and that is the point rather than a limitation. A
	 * marker that could declare "cleared when the handler dismisses it" would
	 * be the per-user unread badge again, and the two would then mean the same
	 * thing while claiming not to.
	 */
	public const CLEAR_CONDITION = 'condition-no-longer-true';

	/**
	 * The markers every case type carries unless it declares its own.
	 *
	 * Three conditions the fleet already produces, each pointing at the panel
	 * where the work behind it is done.
	 *
	 * @var array<int, array<string, string>>
	 */
	public const SHIPPED_MARKERS = [
		[
			'id' => 'advice-request-overdue',
			'label' => 'An advice request is past its date',
			'tab' => 'case-work-panel',
			'raiseWhen' => 'advice-request-overdue',
			'clearWhen' => self::CLEAR_CONDITION,
		],
		[
			'id' => 'term-exceeded',
			'label' => 'The date this case had to be decided by has passed',
			'tab' => 'case-data-panel',
			'raiseWhen' => 'term-exceeded',
			'clearWhen' => self::CLEAR_CONDITION,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $resolver        The effective blueprint of a case
	 *                                          type, so a child type inherits
	 *                                          its parent's markers without
	 *                                          declaring them.
	 * @param LoggerInterface  $logger          Structured logger.
	 * @param SettingsService  $settingsService The OpenRegister seam, for the
	 *                                          rows a condition reads that are
	 *                                          not on the case itself.
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly LoggerInterface $logger,
		private readonly ?SettingsService $settingsService = null,
	) {
	}//end __construct()

	/**
	 * The related rows the conditions read, for one case.
	 *
	 * ONE query, on the same footing as the case-type read the priority
	 * derivation beside this already makes on every save. A failure answers an
	 * EMPTY context rather than an empty row set, and the difference matters:
	 * an empty context means "not asked", which keeps a standing marker,
	 * where an empty row set would mean "asked, and there is nothing", which
	 * clears one. A store that was briefly unreachable must not look like work
	 * somebody finished.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The context.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function contextFor(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '' || $this->settingsService === null) {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: 'adviceRequest',
				filters: ['case' => $caseId, '_limit' => 100]
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: could not read the advice requests behind a marker: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId]
			);
			return [];
		}

		return ['adviceRequests' => $rows];
	}//end contextFor()

	/**
	 * What is wrong with a set of marker declarations.
	 *
	 * Answers one sentence per broken declaration, each NAMING the marker, so
	 * a refusal on publication tells an administrator which of their rows to
	 * fix rather than that something somewhere is wrong.
	 *
	 * @param array<int, mixed> $declarations The declared markers.
	 *
	 * @return array<int, string> The reasons, empty when every declaration is whole.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function validateDeclarations(array $declarations): array {
		$problems = [];
		$seen = [];

		foreach ($declarations as $index => $declaration) {
			if (is_array($declaration) === false) {
				$problems[] = sprintf('Marker %d is not a declaration.', ((int)$index + 1));
				continue;
			}

			$id = trim((string)($declaration['id'] ?? ''));
			$named = sprintf('marker "%s"', $id);
			if ($id === '') {
				$named = sprintf('marker %d', ((int)$index + 1));
			}

			if ($id === '') {
				$problems[] = sprintf('The %s has no id.', $named);
			}

			if ($id !== '' && in_array($id, $seen, true) === true) {
				$problems[] = sprintf('The %s is declared twice.', $named);
			}

			if ($id !== '') {
				$seen[] = $id;
			}

			$tab = trim((string)($declaration['tab'] ?? ''));
			if (in_array($tab, self::PANELS, true) === false) {
				$problems[] = sprintf('The %s points at no panel of the case page.', $named);
			}

			$raise = trim((string)($declaration['raiseWhen'] ?? ''));
			if (in_array($raise, self::RAISE_CONDITIONS, true) === false) {
				$problems[] = sprintf('The %s names no condition that raises it.', $named);
			}

			$clear = trim((string)($declaration['clearWhen'] ?? ''));
			if ($clear !== self::CLEAR_CONDITION) {
				$problems[] = sprintf('The %s names no condition that clears it.', $named);
			}
		}//end foreach

		return $problems;
	}//end validateDeclarations()

	/**
	 * The markers a case type declares, or the shipped set.
	 *
	 * A case type that declares nothing gets the three the app ships, because
	 * a marker nobody administered is still worth raising. A case type that
	 * declares its own gets exactly those, because declaring one and silently
	 * inheriting three more is not something an administrator can reason
	 * about.
	 *
	 * @param string $caseTypeId The case's case type, or the empty string.
	 *
	 * @return array<int, array<string, mixed>> The declarations, each one whole.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function declarationsFor(string $caseTypeId): array {
		$declared = [];
		if (trim($caseTypeId) !== '') {
			try {
				$effective = $this->resolver->effectiveCaseType(caseTypeId: trim($caseTypeId));
				$declared = (array)($effective['attentionMarkers'] ?? []);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not read a case type marker declaration, using the shipped set',
					['caseType' => $caseTypeId, 'error' => $e->getMessage()]
				);
				$declared = [];
			}
		}

		if ($declared === []) {
			$declared = self::SHIPPED_MARKERS;
		}

		$whole = [];
		foreach ($declared as $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			if ($this->validateDeclarations(declarations: [$declaration]) !== []) {
				// A broken declaration raises nothing rather than raising
				// something half-described. Publication already refused it by
				// name; this is the runtime half of the same refusal.
				continue;
			}

			if (($declaration['enabled'] ?? true) === false) {
				continue;
			}

			$whole[] = $declaration;
		}//end foreach

		return $whole;
	}//end declarationsFor()

	/**
	 * The markers standing on this case right now.
	 *
	 * DERIVED, never stored as something a person dismisses. A condition that
	 * stopped being true is simply absent from the next answer, which is what
	 * makes "handling the work clears the marker" true by construction rather
	 * than by a second listener remembering to clear it.
	 *
	 * @param array<string, mixed>   $case    The case being judged.
	 * @param DateTimeImmutable|null $now     Today, for the conditions that ask.
	 * @param array<string, mixed>   $context The related rows, from
	 *                                        `contextFor()`. A condition whose
	 *                                        key is absent is not judged, and
	 *                                        its standing marker is kept.
	 *
	 * @return array<int, array<string, mixed>> The raised markers.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function evaluate(array $case, ?DateTimeImmutable $now = null, array $context = []): array {
		$now = ($now ?? new DateTimeImmutable());
		$caseTypeId = $this->referenceId(value: ($case['caseType'] ?? null));
		$standing = $this->standing(case: $case);

		$raised = [];
		foreach ($this->declarationsFor(caseTypeId: $caseTypeId) as $declaration) {
			$condition = (string)$declaration['raiseWhen'];
			$id = (string)$declaration['id'];

			$needs = (string)(self::CONDITION_CONTEXT[$condition] ?? '');
			if ($needs !== '' && array_key_exists($needs, $context) === false) {
				// The rows this condition reads were not fetched, so this save
				// learnt nothing about it. Keep what was standing; clearing it
				// would say the work was done on no evidence at all.
				$kept = $this->keep(marker: $id, case: $case);
				if ($kept !== null) {
					$raised[] = $kept;
				}

				continue;
			}

			$reason = $this->reasonFor(
				condition: $condition,
				case: $case,
				now: $now,
				context: $context
			);

			if ($reason === '') {
				continue;
			}

			$raised[] = [
				'marker' => $id,
				'tab' => (string)$declaration['tab'],
				'reason' => $reason,
				// A marker that is still standing keeps the moment it FIRST
				// became true. Restamping it on every save would make a
				// three-week-old problem read as new on each edit.
				'raisedAt' => ($standing[$id] ?? $now->format('c')),
			];
		}

		return $raised;
	}//end evaluate()

	/**
	 * A marker already standing on the case, exactly as it stands.
	 *
	 * @param string               $marker The marker id.
	 * @param array<string, mixed> $case   The case.
	 *
	 * @return array<string, mixed>|null The row, or null when none stands.
	 */
	private function keep(string $marker, array $case): ?array {
		$markers = ($case['attentionMarkers'] ?? []);
		if (is_array($markers) === false) {
			return null;
		}

		foreach ($markers as $row) {
			if (is_array($row) === true && (string)($row['marker'] ?? '') === $marker) {
				return $row;
			}
		}

		return null;
	}//end keep()

	/**
	 * The marker fields this save implies.
	 *
	 * @param array<string, mixed>   $case    The case being saved.
	 * @param DateTimeImmutable|null $now     Today.
	 * @param array<string, mixed>   $context The related rows, from `contextFor()`.
	 *
	 * @return array{attentionMarkers: array<int, array<string, mixed>>,
	 *               hasAttentionMarkers: bool} The fields to write back.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function resolve(array $case, ?DateTimeImmutable $now = null, array $context = []): array {
		$markers = $this->evaluate(case: $case, now: $now, context: $context);

		return [
			'attentionMarkers' => $markers,
			'hasAttentionMarkers' => ($markers !== []),
		];
	}//end resolve()

	/**
	 * Why a condition is true on this case, or the empty string.
	 *
	 * One sentence a handler can act on, rather than the condition's own name:
	 * "two documents failed their virus scan" sends somebody to the right
	 * document, and `document-scan-failed` sends them to a glossary.
	 *
	 * @param string               $condition One of RAISE_CONDITIONS.
	 * @param array<string, mixed> $case      The case.
	 * @param DateTimeImmutable    $now       Today.
	 * @param array<string, mixed> $context   The related rows.
	 *
	 * @return string The reason, or the empty string when the condition is false.
	 */
	private function reasonFor(
		string $condition,
		array $case,
		DateTimeImmutable $now,
		array $context = [],
	): string {
		return match ($condition) {
			'advice-request-overdue' => $this->adviceOverdue(context: $context, now: $now),
			'aanvullingsverzoek-open' => $this->openAanvullingsverzoek(case: $case),
			'term-exceeded' => $this->termExceeded(case: $case, now: $now),
			default => '',
		};
	}//end reasonFor()

	/**
	 * Advice requests on this case that are past their date.
	 *
	 * `adviceRequest` is a register object pointing back at the case, never a
	 * property of it, so the rows arrive in the context rather than on `$case`.
	 *
	 * @param array<string, mixed> $context The related rows.
	 * @param DateTimeImmutable    $now     Today.
	 *
	 * @return string The reason, or the empty string.
	 */
	private function adviceOverdue(array $context, DateTimeImmutable $now): string {
		$overdue = 0;
		foreach ($this->rows(context: $context, key: 'adviceRequests') as $request) {
			$status = strtolower(trim((string)($request['status'] ?? '')));
			if (in_array($status, ['received', 'closed', 'withdrawn', 'expired'], true) === true) {
				continue;
			}

			$due = trim((string)($request['deadline'] ?? ''));
			if ($due === '' || $this->hasPassed(date: $due, now: $now) === false) {
				continue;
			}

			$overdue++;
		}

		if ($overdue === 0) {
			return '';
		}

		return sprintf('%d advice request(s) are past the date they were asked for.', $overdue);
	}//end adviceOverdue()

	/**
	 * Whether this case is waiting on an applicant.
	 *
	 * Reads the derived flag `aanvullingsverzoek-as-a-record` writes. It is a
	 * declared condition here so a case type can point a marker at it; the
	 * record and the flag belong to that change and are not restated.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The reason, or the empty string.
	 */
	private function openAanvullingsverzoek(array $case): string {
		if (($case['waitingOnApplicant'] ?? false) !== true) {
			return '';
		}

		return 'This case is waiting on the applicant to complete their submission.';
	}//end openAanvullingsverzoek()

	/**
	 * Whether this case is past the date it had to be decided by.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param DateTimeImmutable    $now  Today.
	 *
	 * @return string The reason, or the empty string.
	 */
	private function termExceeded(array $case, DateTimeImmutable $now): string {
		$deadline = trim((string)($case['deadline'] ?? ($case['deadlineDate'] ?? '')));
		if ($deadline === '' || $this->hasPassed(date: $deadline, now: $now) === false) {
			return '';
		}

		if (($case['isFinalStatus'] ?? false) === true) {
			return '';
		}

		return 'The date this case had to be decided by has passed.';
	}//end termExceeded()

	/**
	 * When each marker standing on this case first became true.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, string> Marker id to moment.
	 */
	private function standing(array $case): array {
		$moments = [];
		$markers = ($case['attentionMarkers'] ?? []);
		if (is_array($markers) === false) {
			return $moments;
		}

		foreach ($markers as $marker) {
			if (is_array($marker) === false) {
				continue;
			}

			$id = trim((string)($marker['marker'] ?? ''));
			$raisedAt = trim((string)($marker['raisedAt'] ?? ''));
			if ($id === '' || $raisedAt === '') {
				continue;
			}

			$moments[$id] = $raisedAt;
		}

		return $moments;
	}//end standing()

	/**
	 * The related rows under one context key, each one an array.
	 *
	 * @param array<string, mixed> $context The context.
	 * @param string               $key     The key.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $context, string $key): array {
		$rows = ($context[$key] ?? []);
		if (is_array($rows) === false) {
			return [];
		}

		$readable = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$readable[] = $row;
			}
		}

		return $readable;
	}//end rows()

	/**
	 * Whether a stored date is behind today.
	 *
	 * @param string            $date The date.
	 * @param DateTimeImmutable $now  Today.
	 *
	 * @return boolean True when it has passed.
	 */
	private function hasPassed(string $date, DateTimeImmutable $now): bool {
		try {
			$moment = new DateTimeImmutable($date);
		} catch (Throwable) {
			return false;
		}

		return ($moment->format('Y-m-d') < $now->format('Y-m-d'));
	}//end hasPassed()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()
}//end class
