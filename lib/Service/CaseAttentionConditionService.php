<?php

/**
 * Dossiq case attention marker conditions.
 *
 * What is TRUE about a case, in the words a handler can act on. The other
 * half, what a marker IS and which panel it points at, is
 * {@see CaseAttentionMarkerService}: this class never decides whether a
 * marker exists, only whether the thing behind one is the case.
 *
 * 🔴 A CONDITION READS EITHER THE CASE OR ITS RELATED ROWS, AND THE TWO ARE
 * NOT THE SAME KIND OF READ. `caseDocuments`, `adviceRequests` and `roles`
 * are NOT properties of a case: each is a separate register object pointing
 * back at it, exactly as `37-unread-state.json` records for notes and
 * contactmomenten. A condition written against `$case['adviceRequests']`
 * would be false on every case for ever and nothing would fail. So the
 * related rows arrive as an explicit context, `CONTEXT` names which condition
 * needs which key, and a condition whose key is absent is not judged at all.
 *
 * THE LIST IS CLOSED AND SHORT ON PURPOSE. A declared condition nothing can
 * evaluate is a marker that never appears and never says why. A condition for
 * a document that failed its virus scan needs `scanVerdict`, which is the open
 * `scan-verdict-on-the-row` change; one for a party whose post came back needs
 * a field `role` does not carry. Both land when the fields do.
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
 * Whether the thing behind a marker is true of this case, and why.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseAttentionConditionService {

	use SearchesObjects;

	/**
	 * The conditions this app can actually evaluate.
	 *
	 * A closed list, because a declared condition nothing evaluates is a
	 * marker that never appears and never says why.
	 *
	 * @var array<int, string>
	 */
	public const CONDITIONS = [
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
	public const CONTEXT = [
		'advice-request-overdue' => 'adviceRequests',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger          Structured logger.
	 * @param SettingsService $settingsService The OpenRegister seam, for the
	 *                                         rows a condition reads that are
	 *                                         not on the case itself.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?SettingsService $settingsService = null,
	) {
	}//end __construct()

	/**
	 * Whether a condition needs rows that are not on the case.
	 *
	 * @param string               $condition One of CONDITIONS.
	 * @param array<string, mixed> $context   The related rows.
	 *
	 * @return boolean True when the rows it reads were not fetched.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function unanswerable(string $condition, array $context): bool {
		$needs = (string)(self::CONTEXT[$condition] ?? '');

		return ($needs !== '' && array_key_exists($needs, $context) === false);
	}//end unanswerable()

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
			$this->logger->warning(
				'Dossiq: the advice requests behind a marker could not be read; '
				. 'every marker that reads them is left exactly as it stood',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			return [];
		}

		return ['adviceRequests' => $rows];
	}//end contextFor()

	/**
	 * Why a condition is true on this case, or the empty string.
	 *
	 * One sentence a handler can act on, rather than the condition's own name:
	 * "two advice requests are past the date they were asked for" sends
	 * somebody to the right request, and `advice-request-overdue` sends them
	 * to a glossary.
	 *
	 * @param string               $condition One of RAISE_CONDITIONS.
	 * @param array<string, mixed> $case      The case.
	 * @param DateTimeImmutable    $now       Today.
	 * @param array<string, mixed> $context   The related rows.
	 *
	 * @return string The reason, or the empty string when the condition is false.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function reasonFor(
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

}//end class
