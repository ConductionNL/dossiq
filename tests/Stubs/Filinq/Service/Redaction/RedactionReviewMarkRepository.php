<?php

/**
 * Filinq RedactionReviewMarkRepository test stub.
 *
 * Filinq's repository keeps review marks in OpenRegister and answers the
 * latest mark per document. This stand-in keeps them in an array with the
 * same two methods and the same answer, so filinq's real RedactionOutputGuard
 * (copied verbatim beside it) runs unchanged in dossiq's unit suite.
 * tests/bootstrap.php loads it only when filinq is absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Filinq\Service\Redaction;

/**
 * Review marks in memory.
 */
class RedactionReviewMarkRepository {

	/**
	 * Every saved mark, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $marks = [];

	/**
	 * The latest mark covering one document, or null when there is none.
	 *
	 * @param string $document The document the mark is about.
	 *
	 * @return array<string, mixed>|null The mark.
	 */
	public function findFor(string $document): ?array {
		$latest = null;
		foreach ($this->marks as $mark) {
			if ((string)($mark['document'] ?? '') === $document) {
				$latest = $mark;
			}
		}

		return $latest;
	}//end findFor()

	/**
	 * Write one mark.
	 *
	 * @param array<string, mixed> $mark The mark.
	 *
	 * @return array<string, mixed> The stored mark.
	 */
	public function save(array $mark): array {
		$this->marks[] = $mark;

		return $mark;
	}//end save()
}//end class
