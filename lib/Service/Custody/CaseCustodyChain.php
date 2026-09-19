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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Opening, closing and reading the holdings of a case.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function move(
		string $caseId,
		string $organisationUnit,
		string $handler,
		string $reason,
		string $movedBy,
		string $movedAt = '',
	): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			throw new RefusedException(
				rule: self::CUSTODY_UNWRITABLE,
				sentence: 'A holding needs a case, so nothing was recorded.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$moment = $this->moment(candidate: $movedAt);
		$previous = $this->openHoldingFor(caseId: $caseId);
		$sequence = 1;
		if ($previous !== null) {
			$sequence = ((int)($previous['sequence'] ?? 0) + 1);
		}

		$opened = $this->write(
			holding: [
				'caseId' => $caseId,
				'organisationUnit' => trim($organisationUnit),
				'handler' => trim($handler),
				'from' => $moment,
				'until' => '',
				'open' => true,
				'reason' => trim($reason),
				'movedBy' => trim($movedBy),
				'sequence' => $sequence,
			],
			uuid: null,
		);

		if ($previous !== null) {
			$this->close(holding: $previous, closedAt: $moment);
		}

		$this->logger->info(
			'Dossiq custody: a case changed hands',
			[
				'caseId' => $caseId,
				'to' => $organisationUnit,
				'sequence' => $sequence,
				'movedBy' => $movedBy,
			],
		);

		return $opened;
	}//end move()

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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function begin(
		string $caseId,
		string $organisationUnit,
		string $handler,
		string $from,
		string $reason,
		string $movedBy,
	): ?array {
		$caseId = trim($caseId);
		if ($caseId === '' || $this->openHoldingFor(caseId: $caseId) !== null) {
			return null;
		}

		return $this->write(
			holding: [
				'caseId' => $caseId,
				'organisationUnit' => trim($organisationUnit),
				'handler' => trim($handler),
				'from' => $this->moment(candidate: $from),
				'until' => '',
				'open' => true,
				'reason' => trim($reason),
				'movedBy' => trim($movedBy),
				'sequence' => 1,
			],
			uuid: null,
		);
	}//end begin()

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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holdings(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		try {
			[$objectService, $register] = $this->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_custody_schema'),
				filters: ['caseId' => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq custody: the chain could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return [];
		}

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
			[$objectService, $register] = $this->context();
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_custody_schema'),
				object: $holding,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::CUSTODY_UNWRITABLE,
				sentence: 'The chain of custody could not be written, so the case was not moved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($saved === null) {
			throw new RefusedException(
				rule: self::CUSTODY_UNWRITABLE,
				sentence: 'The chain of custody could not be written, so the case was not moved.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $saved;
	}//end write()

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
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
