<?php

/**
 * Dossiq filinq redaction: the three filinq calls of a redaction.
 *
 * Asking filinq to detect a file's passages, to record the officer's check
 * and to write the redacted copy, each refused in a sentence the officer
 * reads when filinq cannot.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-redaction/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FilinqRedactionClient;
use OCP\IL10N;
use RuntimeException;

/**
 * The filinq calls of DocumentRedactionService. A plain helper: the service builds it.
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
final class FilinqRedaction {

	/**
	 * Constructor.
	 *
	 * @param FilinqRedactionClient $filinq Detection, the check and the redacted copy, in filinq.
	 * @param IL10N                 $l10n   The refusal sentences.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FilinqRedactionClient $filinq,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The passages filinq detects in a file, as filinq answers them.
	 *
	 * @param int $fileId The file.
	 *
	 * @return array<mixed> The passages.
	 *
	 * @throws RefusedException When filinq cannot detect.
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 */
	public function detect(int $fileId): array {
		try {
			return $this->filinq->detect(fileId: $fileId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'detection-unavailable',
				sentence: $this->l10n->t('Filinq could not detect the passages of this document. Upload a redacted version instead.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end detect()

	/**
	 * Record the officer's check in filinq.
	 *
	 * @param RedactionRun $run     The step, its file resolved.
	 * @param array<mixed> $checked The checked passages.
	 *
	 * @return void
	 *
	 * @throws RefusedException When filinq cannot record the check.
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 */
	public function markChecked(RedactionRun $run, array $checked): void {
		try {
			$this->filinq->markChecked(fileId: $run->fileId, entities: $checked, checkedBy: $run->userId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'check-not-recorded',
				sentence: $this->l10n->t('Filinq could not record your check. Try again, or upload a redacted version.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end markChecked()

	/**
	 * What filinq answers when it writes the redacted copy of the checked passages.
	 *
	 * @param RedactionRun $run The step, checked on this file.
	 *
	 * @return array<string, mixed> The outcome, its status `redacted`.
	 *
	 * @throws RefusedException When filinq fails or wrote no copy.
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 */
	public function anonymize(RedactionRun $run): array {
		$outcome = $this->anonymized(run: $run);
		if (($outcome['status'] ?? '') !== 'redacted') {
			throw $this->refusalFor(status: (string)($outcome['status'] ?? ''));
		}

		return $outcome;
	}//end anonymize()

	/**
	 * What filinq answers to the request for a redacted copy.
	 *
	 * @param RedactionRun $run The step, checked on this file.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @throws RefusedException When filinq fails.
	 */
	private function anonymized(RedactionRun $run): array {
		try {
			return $this->filinq->anonymize(fileId: $run->fileId, entities: (array)($run->redaction['entities'] ?? []), userId: $run->userId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'redaction-failed',
				sentence: $this->l10n->t('Filinq could not make the redacted copy. Try again, or upload a redacted version.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end anonymized()

	/**
	 * The refusal for an outcome in which filinq wrote no redacted copy.
	 *
	 * @param string $status Filinq's outcome.
	 *
	 * @return RefusedException
	 */
	private function refusalFor(string $status): RefusedException {
		return match ($status) {
			'detection_unavailable' => new RefusedException(
				rule: 'detection-unavailable',
				sentence: $this->l10n->t(
					'Entity detection is off, so filinq makes no redacted copy. Ask an administrator to turn it on, or upload a redacted version.'
				),
				status: RefusedException::STATUS_INDETERMINATE
			),
			'not_checked' => new RefusedException(
				rule: 'not-checked',
				sentence: $this->l10n->t('Filinq has no check for these passages. Confirm the list again.')
			),
			default => new RefusedException(
				rule: 'no-redacted-copy',
				sentence: $this->l10n->t('Filinq wrote no redacted copy of this document. Upload a redacted version instead.')
			),
		};
	}//end refusalFor()
}//end class
