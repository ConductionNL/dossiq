<?php

/**
 * The dated chain of holdings a case passes through.
 *
 * The audit trail already says what changed and when. It does not say who held
 * this case in March, because reading a sequence of diffs back into a holding
 * is a reconstruction, and a reconstruction is not a record. A complaint, a WOO
 * request or an internal review asks the second question, so custody is its own
 * record: one row per period, with the unit, the handler, the start, the end,
 * the reason and who moved it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opening, closing and reading the holdings of a case.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseCustodyChain {

	use SearchesObjects;

	/**
	 * The rule a move refuses under when the chain cannot be written.
	 *
	 * @var string
	 */
	public const CUSTODY_UNWRITABLE = 'custody-unwritable';

	/**
	 * How many holdings one read takes at most.
	 *
	 * A case that changed hands two hundred times is a defect in its own
	 * right, and a page this size makes that visible rather than silently
	 * truncating a chain the caller then reads as complete.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records every move and every failure to record one.
	 * @param CaseDateNormaliser $dates        The one class that may resolve a time zone. A holding
	 *                                         is a case date, so the chain reads and writes its
	 *                                         moments through it rather than parsing them here.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Move the case into a new holding, closing the one that was open.
	 *
	 * 🔑 THE NEXT HOLDING IS WRITTEN BEFORE THE PREVIOUS ONE IS CLOSED, and
	 * that order is deliberate. OpenRegister gives no transaction across two
	 * objects, so one of the two windows is unavoidable. Closing first leaves a
	 * moment where the case is held by NOBODY, which reads as an answer and is
	 * the failure D-2 calls worse than having no chain at all. Opening first
	 * leaves a moment with two open holdings, which every reader here resolves
	 * by the highest sequence, and which a reader outside can see is wrong.
	 *
	 * @param string $caseId           The case that is moving.
	 * @param string $organisationUnit The unit taking the case.
	 * @param string $handler          The person on the seat, or an empty string when nobody is named.
	 * @param string $reason           Why the case is moving.
	 * @param string $movedBy          Who made the move.
	 * @param string $movedAt          The moment of the move in ISO 8601, or an empty string for now.
	 *
	 * @return array<string, mixed> The holding that is now open.
	 *
	 * @throws RefusedException When the chain cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function move(
		string $caseId,
		string $organisationUnit,
		string $handler,
		string $reason,
		string $movedBy,
		string $movedAt = '',
	): array {
		return $this->moveCase(
			move: new CustodyMove(caseId: $caseId, organisationUnit: $organisationUnit, handler: $handler, reason: $reason, movedBy: $movedBy, at: $movedAt),
		);
	}//end move()

	/**
	 * Move the case into a new holding, closing the one that was open.
	 *
	 * Writes the next holding before closing the previous one, for the reason
	 * given on {@see move()}.
	 *
	 * @param CustodyMove $move The change of hands.
	 *
	 * @return array<string, mixed> The holding that is now open.
	 *
	 * @throws RefusedException When the move names no case or the chain cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function moveCase(CustodyMove $move): array {
		$caseId = $this->requireCaseId(move: $move);
		$moment = $this->moment(candidate: $move->at);
		$previous = $this->openHoldingFor(caseId: $caseId);
		$sequence = $this->sequenceAfter(previous: $previous);

		$opened = $this->write(holding: $move->openHolding(from: $moment, sequence: $sequence), uuid: null);

		if ($previous !== null) {
			$this->close(holding: $previous, closedAt: $moment);
		}

		$this->logMove(move: $move, sequence: $sequence);

		return $opened;
	}//end moveCase()

	/**
	 * The trimmed case id of a move, refusing a move that names no case.
	 *
	 * @param CustodyMove $move The change of hands.
	 *
	 * @return string The case id.
	 *
	 * @throws RefusedException When the case id is empty.
	 */
	private function requireCaseId(CustodyMove $move): string {
		$caseId = trim($move->caseId);
		if ($caseId === '') {
			throw new RefusedException(
				rule: self::CUSTODY_UNWRITABLE,
				sentence: 'A holding needs a case, so nothing was recorded.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $caseId;
	}//end requireCaseId()

	/**
	 * The sequence of the holding that follows the open one, or one on an empty chain.
	 *
	 * @param array<string, mixed>|null $previous The holding that is open now, if any.
	 *
	 * @return int The next sequence.
	 */
	private function sequenceAfter(?array $previous): int {
		if ($previous === null) {
			return 1;
		}

		return ((int)($previous['sequence'] ?? 0) + 1);
	}//end sequenceAfter()

	/**
	 * Record that a case changed hands.
	 *
	 * @param CustodyMove $move     The change of hands.
	 * @param int         $sequence The sequence of the holding it opened.
	 *
	 * @return void
	 */
	private function logMove(CustodyMove $move, int $sequence): void {
		$this->logger->info(
			'Dossiq custody: a case changed hands',
			[
				'caseId' => trim($move->caseId),
				'to' => $move->organisationUnit,
				'sequence' => $sequence,
				'movedBy' => $move->movedBy,
			],
		);
	}//end logMove()

	/**
	 * Open the first holding of a case, where the chain has none yet.
	 *
	 * Used by the backfill, which dates the first holding from the case start
	 * rather than from the moment the backfill ran: a chain that begins when
	 * somebody remembered to write it is a chain with a hole at the front.
	 *
	 * @param string $caseId           The case.
	 * @param string $organisationUnit The unit that has held it since the start.
	 * @param string $handler          The person on the seat, or an empty string.
	 * @param string $from             The moment the holding began, in ISO 8601.
	 * @param string $reason           Why this holding exists.
	 * @param string $movedBy          Who is credited with it.
	 *
	 * @return array<string, mixed>|null The holding, or null when one was already open.
	 *
	 * @throws RefusedException When the chain cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function begin(
		string $caseId,
		string $organisationUnit,
		string $handler,
		string $from,
		string $reason,
		string $movedBy,
	): ?array {
		return $this->beginCase(
			move: new CustodyMove(caseId: $caseId, organisationUnit: $organisationUnit, handler: $handler, reason: $reason, movedBy: $movedBy, at: $from),
		);
	}//end begin()

	/**
	 * Open the first holding of a case, dated from the moment on the move.
	 *
	 * @param CustodyMove $move The first holding; its moment is when the holding began.
	 *
	 * @return array<string, mixed>|null The holding, or null when one was already open.
	 *
	 * @throws RefusedException When the chain cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function beginCase(CustodyMove $move): ?array {
		$caseId = trim($move->caseId);
		if ($caseId === '' || $this->openHoldingFor(caseId: $caseId) !== null) {
			return null;
		}

		return $this->write(holding: $move->openHolding(from: $this->moment(candidate: $move->at), sequence: 1), uuid: null);
	}//end beginCase()

	/**
	 * The holding that is running now, or null when the chain is empty.
	 *
	 * Resolves the transient two-open window documented on {@see move()} by
	 * taking the highest sequence, so a reader never has to guess.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The open holding.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function openHoldingFor(string $caseId): ?array {
		$open = [];
		foreach ($this->holdings(caseId: $caseId) as $holding) {
			if (($holding['open'] ?? false) === true) {
				$open[] = $holding;
			}
		}

		if ($open === []) {
			return null;
		}

		return $open[(count($open) - 1)];
	}//end openHoldingFor()

	/**
	 * Every holding of a case, oldest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The chain.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holdings(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		$rows = (new CustodyRows(settingsService: $this->settingsService, logger: $this->logger))->find(
			filters: ['caseId' => $caseId, '_limit' => self::PAGE_SIZE],
			failure: 'Dossiq custody: the chain could not be read',
		);

		usort(
			$rows,
			static function (array $left, array $right): int {
				return ((int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0));
			},
		);

		return $rows;
	}//end holdings()

	/**
	 * Close a holding at a moment.
	 *
	 * @param array<string, mixed> $holding The holding as it was read.
	 * @param string               $closedAt The closing moment in ISO 8601.
	 *
	 * @return void
	 */
	private function close(array $holding, string $closedAt): void {
		$uuid = trim((string)($holding['id'] ?? ($holding['uuid'] ?? '')));
		if ($uuid === '') {
			$this->logger->error(
				'Dossiq custody: a holding without an id could not be closed',
				['caseId' => ($holding['caseId'] ?? '')],
			);

			return;
		}

		$holding['until'] = $closedAt;
		$holding['open'] = false;

		try {
			$this->write(holding: $holding, uuid: $uuid);
		} catch (RefusedException $e) {
			// The next holding is already open, so the case is held by
			// somebody. Two open holdings is the recoverable half of the pair;
			// see the note on move().
			$this->logger->error(
				'Dossiq custody: the previous holding stayed open',
				['caseId' => ($holding['caseId'] ?? ''), 'holding' => $uuid],
			);
		}
	}//end close()

	/**
	 * Store a holding, new or existing.
	 *
	 * @param array<string, mixed> $holding The holding.
	 * @param string|null          $uuid    The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored holding.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function write(array $holding, ?string $uuid): array {
		unset($holding['@self'], $holding['id'], $holding['uuid']);

		try {
			$saved = $this->saveHolding(holding: $holding, uuid: $uuid);
		} catch (Throwable $e) {
			throw $this->unwritable(previous: $e);
		}

		if ($saved === null) {
			throw $this->unwritable(previous: null);
		}

		return $saved;
	}//end write()

	/**
	 * Save a holding in the custody schema.
	 *
	 * @param array<string, mixed> $holding The holding, without its identifiers.
	 * @param string|null          $uuid    The uuid to update, or null to create.
	 *
	 * @return array<string, mixed>|null The stored holding, or null when nothing was stored.
	 *
	 * @throws Throwable When the register is unavailable or refuses the write.
	 */
	private function saveHolding(array $holding, ?string $uuid): ?array {
		[$objectService, $register] = $this->dossierRegister()->context();

		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->dossierRegister()->schema(key: 'case_custody_schema'),
			object: $holding,
			uuid: $uuid,
		);
	}//end saveHolding()

	/**
	 * The refusal for a chain that could not be written.
	 *
	 * @param Throwable|null $previous The failure underneath, when there was one.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unwritable(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: self::CUSTODY_UNWRITABLE,
			sentence: 'The chain of custody could not be written, so the case was not moved.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unwritable()

	/**
	 * A moment in ISO 8601: the one given, or now.
	 *
	 * @param string $candidate The candidate moment.
	 *
	 * @return string The moment.
	 */
	private function moment(string $candidate): string {
		$candidate = trim($candidate);
		if ($candidate === '') {
			return $this->dates->nowAsMoment();
		}

		// Read through CaseDateNormaliser and not parsed here. A holding is a
		// case date, and a second rule for what a date is, is how one moment
		// on the chain ends up in the server zone and the next in the
		// administered one (openspec/changes/one-date-write-path). An
		// unreadable value reads as now, which is what it read as before.
		$parsed = $this->dates->tryParse($candidate);
		if ($parsed === null) {
			return $this->dates->nowAsMoment();
		}

		return $this->dates->formatMoment($parsed);
	}//end moment()
	/**
	 * The dossier register and its configured schemas.
	 *
	 * @return DossierRegister The register reader.
	 */
	private function dossierRegister(): DossierRegister {
		return new DossierRegister(settingsService: $this->settingsService);
	}//end dossierRegister()
}//end class
