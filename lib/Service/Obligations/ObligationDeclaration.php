<?php

/**
 * What an obligation is, read off the declaration rather than off a service.
 *
 * `ConsultationService::getBlockingConsultations()` is the right behaviour
 * written in the wrong place: inside the one thing that happened to need it. A
 * second obligation, an unpaid fee, an inspection not yet done, an external
 * approval, would each be a second service with the same three parts written
 * again, and that is how a codebase ends up with four of them that behave
 * differently.
 *
 * So an obligation has exactly three parts, declared on the case type: it is
 * PLACED on somebody, it BLOCKS something while it is open, and MEETING it
 * releases what it blocked. A new kind of obligation then declares what settles
 * it and nothing else.
 *
 * Every method takes the row rather than its id, so the whole class is
 * exercisable without a register.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Obligations
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
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Obligations;

/**
 * Reads the obligation kinds a case type declares.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */
class ObligationDeclaration {

	/**
	 * The obligation is open: it has been placed and nothing has settled it.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * The obligation has been met, and what it blocked is released.
	 *
	 * @var string
	 */
	public const STATE_MET = 'met';

	/**
	 * The obligation was withdrawn with a reason, and what it blocked is
	 * released. Not the same fact as met, and never recorded as one: an
	 * inspection nobody carried out is not an inspection that passed.
	 *
	 * @var string
	 */
	public const STATE_WITHDRAWN = 'withdrawn';

	/**
	 * The states in which an obligation still blocks.
	 *
	 * @var array<int, string>
	 */
	public const BLOCKING_STATES = [self::STATE_OPEN];

	/**
	 * The token a declaration uses for "every status that closes the case".
	 *
	 * Spelled as a token rather than as a list of status ids, because the
	 * failure this closes is a case type with three closing statuses where the
	 * declaration named one. A list has to be kept complete by hand; the token
	 * cannot go stale.
	 *
	 * @var string
	 */
	public const BLOCKS_CLOSING = 'closing';

	/**
	 * The obligation kinds this case type declares, keyed by kind.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<string, array<string, mixed>> The declarations.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function kindsFor(array $caseType): array {
		$declared = ($caseType['obligationKinds'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$kinds = [];
		foreach ($declared as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$kind = trim((string)($row['kind'] ?? ''));
			if ($kind === '') {
				continue;
			}

			$kinds[$kind] = [
				'kind' => $kind,
				'title' => (string)($row['title'] ?? $kind),
				'placedOnRole' => (string)($row['placedOnRole'] ?? ''),
				'settledBy' => (string)($row['settledBy'] ?? ''),
				'blocks' => $this->blocksOf(row: $row),
				'term' => $this->termOf(row: $row),
			];
		}

		return $kinds;
	}//end kindsFor()

	/**
	 * What one declared kind blocks while it is open.
	 *
	 * @param array<string, mixed> $row The declaration row.
	 *
	 * @return array<int, string> Status ids, or the closing token.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function blocksOf(array $row): array {
		$blocks = ($row['blocks'] ?? null);
		if (is_string($blocks) === true) {
			$blocks = [$blocks];
		}

		if (is_array($blocks) === false) {
			return [self::BLOCKS_CLOSING];
		}

		$list = [];
		foreach ($blocks as $entry) {
			$value = trim((string)$entry);
			if ($value !== '') {
				$list[] = $value;
			}
		}

		if ($list === []) {
			return [self::BLOCKS_CLOSING];
		}

		return $list;
	}//end blocksOf()

	/**
	 * The term one declared kind carries, in working days.
	 *
	 * @param array<string, mixed> $row The declaration row.
	 *
	 * @return int|null The term, or null when the kind declares none.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function termOf(array $row): ?int {
		$term = ($row['term'] ?? null);
		if (is_numeric($term) === false) {
			return null;
		}

		$days = (int)$term;
		if ($days < 1) {
			return null;
		}

		return $days;
	}//end termOf()

	/**
	 * Whether an obligation row still blocks what it was placed against.
	 *
	 * An unknown state BLOCKS. A row whose state nobody recognises is a row
	 * nobody can say is settled, and releasing a case on the strength of a
	 * value this app does not understand is the failure that direction has.
	 *
	 * @param array<string, mixed> $obligation The obligation row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function isBlocking(array $obligation): bool {
		$state = trim((string)($obligation['state'] ?? self::STATE_OPEN));

		return in_array($state, [self::STATE_MET, self::STATE_WITHDRAWN], true) === false;
	}//end isBlocking()

	/**
	 * Whether an open obligation blocks a move into this status.
	 *
	 * @param array<string, mixed> $obligation The obligation row.
	 * @param string               $statusId   The destination status.
	 * @param bool                 $isClosing  Whether that status closes the case.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function blocksStatus(array $obligation, string $statusId, bool $isClosing): bool {
		if ($this->isBlocking(obligation: $obligation) === false) {
			return false;
		}

		$blocks = ($obligation['blocks'] ?? null);
		if (is_array($blocks) === false || $blocks === []) {
			return $isClosing;
		}

		foreach ($blocks as $entry) {
			$value = trim((string)$entry);
			if ($value === self::BLOCKS_CLOSING && $isClosing === true) {
				return true;
			}

			if ($value !== '' && $value === $statusId) {
				return true;
			}
		}

		return false;
	}//end blocksStatus()
}//end class
