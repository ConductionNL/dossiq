<?php

/**
 * Dossiq case attention flag service.
 *
 * REQ-MRK-01: a case carries an attention flag that is raised with a written
 * reason and cleared with a written reason, each act recording who performed
 * it and when.
 *
 * WHY THE CLEARING IS THE ACT WORTH GOVERNING. Raising a flag is easy to ask
 * for and easy to give. The act that decides whether the flag means anything
 * is the clearing, because that is the one somebody does to make a nuisance go
 * away. So a clearing with no reason is refused exactly as a raising with no
 * reason is, and neither deletes what came before (design D-1).
 *
 * THE HISTORY IS THE SIGNAL, so each act is a ROW rather than a field that
 * toggles. A case flagged once is a case with a problem. A case flagged four
 * times by four people is a different case, and the difference is only visible
 * because no earlier reason was overwritten (design D-2). `needsAttention` is
 * derived from the last row, so the facet the work list reads and the history
 * a handler reads cannot answer differently.
 *
 * Neither the name nor the moment is offered as a field. Both are facts about
 * the act, so both are written from the session, the same way
 * `CasePriorityDerivationListener` stamps an override.
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
use RuntimeException;
use Throwable;

/**
 * Raise and clear the attention flag, and keep every reason.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseAttentionFlagService {

	use SearchesObjects;

	/**
	 * The two acts a history row can record.
	 *
	 * @var array<int, string>
	 */
	public const ACTS = ['raised', 'cleared'];

	/**
	 * The shortest reason either act accepts.
	 *
	 * A reason of one character is a reason nobody can act on, and a required
	 * field satisfied by a full stop is the failure mode this whole
	 * requirement exists to prevent. Three is deliberately low: the point is
	 * to refuse the empty gesture, not to grade the prose.
	 */
	public const MINIMUM_REASON_LENGTH = 3;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (OpenRegister access).
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Where the flag on this case stands, and what it has been through.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>, history: array<int, array<string, mixed>>, raisings: int, clearings: int} The flag.
	 *
	 * @throws RuntimeException With code `case_not_found`.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function state(string $caseId): array {
		return $this->describe(caseId: $caseId, case: $this->read(caseId: $caseId));
	}//end state()

	/**
	 * Raise the flag on this case, with a reason and a name.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId Who is raising it.
	 * @param string $reason Why the case needs attention.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>, history: array<int, array<string, mixed>>, raisings: int, clearings: int} The flag.
	 *
	 * @throws RuntimeException With code `case_not_found`, `reason_required` or `already_raised`.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function raise(string $caseId, string $userId, string $reason): array {
		$reason = $this->requireReason(reason: $reason);
		$case = $this->read(caseId: $caseId);

		if ($this->isRaised(case: $case) === true) {
			throw new RuntimeException('already_raised');
		}

		$moment = (new DateTimeImmutable())->format('c');
		$history = $this->history(case: $case);
		$history[] = [
			'act' => 'raised',
			'reason' => $reason,
			'actor' => $userId,
			'moment' => $moment,
		];

		$changes = [
			'needsAttention' => true,
			'attentionFlag' => [
				'reason' => $reason,
				'raisedBy' => $userId,
				'raisedAt' => $moment,
			],
			'attentionFlagHistory' => $history,
		];

		$this->write(caseId: $caseId, changes: $changes);

		return $this->describe(caseId: $caseId, case: array_merge($case, $changes));
	}//end raise()

	/**
	 * Clear the flag on this case, with a reason and a name.
	 *
	 * Clearing does not delete the raising: it appends a second row. That is
	 * the whole of design D-2, and it is why this method never unsets
	 * `attentionFlagHistory`.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId Who is clearing it.
	 * @param string $reason Why it no longer needs attention.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>, history: array<int, array<string, mixed>>, raisings: int, clearings: int} The flag.
	 *
	 * @throws RuntimeException With code `case_not_found`, `reason_required` or `not_raised`.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function clear(string $caseId, string $userId, string $reason): array {
		$reason = $this->requireReason(reason: $reason);
		$case = $this->read(caseId: $caseId);

		if ($this->isRaised(case: $case) === false) {
			throw new RuntimeException('not_raised');
		}

		$history = $this->history(case: $case);
		$history[] = [
			'act' => 'cleared',
			'reason' => $reason,
			'actor' => $userId,
			'moment' => (new DateTimeImmutable())->format('c'),
		];

		$changes = [
			'needsAttention' => false,
			'attentionFlag' => null,
			'attentionFlagHistory' => $history,
		];

		$this->write(caseId: $caseId, changes: $changes);

		return $this->describe(caseId: $caseId, case: array_merge($case, $changes));
	}//end clear()

	/**
	 * The reason both acts require, or the refusal that names what is missing.
	 *
	 * @param string $reason What the caller sent.
	 *
	 * @return string The trimmed reason.
	 *
	 * @throws RuntimeException With code `reason_required`.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function requireReason(string $reason): string {
		$reason = trim($reason);
		if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
			throw new RuntimeException('reason_required');
		}

		return $reason;
	}//end requireReason()

	/**
	 * The flag as a reader sees it, counts included.
	 *
	 * One shape for all three gestures, so the state after a raising and the
	 * state a page reloads cannot describe the same case differently.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $case   The stored case.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>, history: array<int, array<string, mixed>>, raisings: int, clearings: int} The flag.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function describe(string $caseId, array $case): array {
		$history = $this->history(case: $case);
		$acts = array_column($history, 'act');

		return [
			'caseId' => $caseId,
			'raised' => $this->isRaised(case: $case),
			'flag' => (array)($case['attentionFlag'] ?? []),
			'history' => $history,
			'raisings' => count(array_keys($acts, 'raised', true)),
			'clearings' => count(array_keys($acts, 'cleared', true)),
		];
	}//end describe()

	/**
	 * Whether the flag on this case is standing.
	 *
	 * Read from the LAST history row rather than from the boolean, so a case
	 * whose `needsAttention` was written by something other than these two
	 * gestures still answers what its history says. A case with no history
	 * has no flag, whatever the boolean says.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return boolean True when the flag is raised.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function isRaised(array $case): bool {
		$history = $this->history(case: $case);
		if ($history === []) {
			return false;
		}

		return ((string)(end($history)['act'] ?? '') === 'raised');
	}//end isRaised()

	/**
	 * The history rows this case carries, oldest first and each one readable.
	 *
	 * A row missing its act, its reason or its name is dropped rather than
	 * shown: a half-written row in a history that exists to be evidence is
	 * worse than no row, because it reads as an act somebody performed.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function history(array $case): array {
		$rows = ($case['attentionFlagHistory'] ?? []);
		if (is_array($rows) === false) {
			return [];
		}

		$readable = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$act = trim((string)($row['act'] ?? ''));
			$reason = trim((string)($row['reason'] ?? ''));
			if (in_array($act, self::ACTS, true) === false || $reason === '') {
				continue;
			}

			$readable[] = [
				'act' => $act,
				'reason' => $reason,
				'actor' => trim((string)($row['actor'] ?? '')),
				'moment' => trim((string)($row['moment'] ?? '')),
			];
		}

		return $readable;
	}//end history()

	/**
	 * Read the stored case, as the signed-in user.
	 *
	 * A case OpenRegister will not show this person answers the same
	 * `case_not_found` a missing case does, deliberately: a 404 that only
	 * appears for cases you may not see is an existence oracle.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RuntimeException With code `case_not_found`.
	 */
	private function read(string $caseId): array {
		[$objectService, $register, $schema] = $this->openRegister();

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq CaseAttentionFlagService: case lookup failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId]
			);
			throw new RuntimeException('case_not_found');
		}

		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return $case;
	}//end read()

	/**
	 * Write the flag fields onto the stored case, and nothing else.
	 *
	 * A partial write, never a saved snapshot: the case has more writers than
	 * this one, and saving a whole read-back object would put every other
	 * property back as it stood a moment ago.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With code `attention_write_failed`.
	 */
	private function write(string $caseId, array $changes): void {
		[$objectService, $register, $schema] = $this->openRegister();

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq CaseAttentionFlagService: attention write failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId]
			);
			throw new RuntimeException('attention_write_failed');
		}
	}//end write()

	/**
	 * The OpenRegister seam and the case schema, or a refusal.
	 *
	 * @return array{0: object, 1: string, 2: string} The object service, register and schema.
	 *
	 * @throws RuntimeException With code `case_not_found` when OpenRegister or the schema is absent.
	 */
	private function openRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning(
				'Dossiq CaseAttentionFlagService: OpenRegister unavailable',
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('case_not_found');
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq CaseAttentionFlagService: case schema not configured',
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('case_not_found');
		}

		return [$objectService, $register, $schema];
	}//end openRegister()
}//end class
