<?php

/**
 * Dossiq case deadline mirror.
 *
 * A case's `deadline` follows its statutory term instance's `endDateCurrent`
 * after every change to the term: an extension, a pause, a resumption
 * (REQ-WTR-001). The case schema declares `deadline` readOnly, and
 * OpenRegister refuses an UPDATE whose payload changes a readOnly field, so a
 * save that copies the new date onto the case is rejected before any listener
 * runs. A pre-persist listener's `setModifiedData` is merged AFTER that check.
 *
 * So the mirror is two halves. The term write path ({@see
 * \OCA\Dossiq\Service\TermijnService}) records the date the case must carry
 * here and saves the case unchanged. {@see
 * \OCA\Dossiq\Listener\CaseDeadlineListener} takes the date on that save's
 * `ObjectUpdatingEvent` and writes it. The two run in one request, and the
 * container hands both the same instance.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * The deadline a case must carry on its next save, keyed by case id.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class CaseDeadlineMirror {

	/**
	 * Pending deadlines, keyed by case id.
	 *
	 * @var array<string, array{deadline: string, deadlineBeforeRoll: string}>
	 */
	private array $pending = [];

	/**
	 * Record the deadline a case must carry on its next save.
	 *
	 * @param string $caseId             The case.
	 * @param string $deadline           The term instance's `endDateCurrent` (Y-m-d).
	 * @param string $deadlineBeforeRoll The date before the Awt roll, or the empty string.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function expect(string $caseId, string $deadline, string $deadlineBeforeRoll = ''): void {
		if ($caseId === '' || $deadline === '') {
			return;
		}

		$this->pending[$caseId] = ['deadline' => $deadline, 'deadlineBeforeRoll' => $deadlineBeforeRoll];
	}//end expect()

	/**
	 * Take the deadline recorded for a case, once.
	 *
	 * @param string $caseId The case being saved.
	 *
	 * @return array{deadline: string, deadlineBeforeRoll: string}|null The pending dates, or null.
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function take(string $caseId): ?array {
		if (isset($this->pending[$caseId]) === false) {
			return null;
		}

		$pending = $this->pending[$caseId];
		unset($this->pending[$caseId]);

		return $pending;
	}//end take()

	/**
	 * Forget a pending deadline whose case save did not happen.
	 *
	 * @param string $caseId The case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function forget(string $caseId): void {
		unset($this->pending[$caseId]);
	}//end forget()
}//end class
