<?php

/**
 * An obligation placed elsewhere, and the case waiting for it.
 *
 * Consulting another department is a hand-off with three parts: the obligation
 * goes out, the case waits, and it comes back when the obligation is met.
 * dossiq had that pattern once, wired to advice requests, and every second
 * obligation would have been a second service with the same three parts
 * written again.
 *
 * This is those three parts written ONCE. What differs per kind is what
 * settles it and what it blocks, and both are declared on the case type rather
 * than written in PHP. The advice request becomes the first obligation of this
 * kind rather than staying a mechanism beside it; leaving it beside would
 * leave two, which is the state the row this change closes already describes.
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

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places, settles and withdraws the obligations a case is waiting on.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */
class ObligationService {

	use SearchesObjects;

	/**
	 * The settings key naming the obligation schema.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'obligation_schema';

	/**
	 * The kind the advice request places.
	 *
	 * Named here rather than in `ConsultationService` because the transition
	 * that withholds on it names the same string, and a literal spelled in two
	 * files is a literal that eventually differs in one of them.
	 *
	 * @var string
	 */
	public const KIND_ADVICE = 'advice';

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService Resolves the object service and schemas.
	 * @param ObligationDeclaration $declaration     What an obligation is.
	 * @param CaseDateNormaliser    $dates           The one date write path.
	 * @param LoggerInterface       $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ObligationDeclaration $declaration,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Place an obligation on somebody, for a case.
	 *
	 * @param array<string, mixed> $obligation What is placed: case, kind, title,
	 *                                         placedOn, blocks, dueAt, source.
	 *
	 * @return string The obligation id, or the empty string when it could not be placed.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function place(array $obligation): string {
		$caseId = trim((string)($obligation['case'] ?? ''));
		$kind = trim((string)($obligation['kind'] ?? ''));
		if ($caseId === '' || $kind === '') {
			return '';
		}

		$payload = $obligation;
		$payload['state'] = ObligationDeclaration::STATE_OPEN;
		$payload['placedAt'] = $this->dates->nowAsMoment();

		return $this->save(payload: $payload, context: 'place');
	}//end place()

	/**
	 * Record that an obligation has been met, releasing what it blocked.
	 *
	 * @param string $obligationId The obligation.
	 * @param string $note         What settled it, for the record.
	 *
	 * @return bool True when the obligation was settled.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function meet(string $obligationId, string $note = ''): bool {
		return $this->close(
			obligationId: $obligationId,
			state: ObligationDeclaration::STATE_MET,
			field: 'metAt',
			reason: $note,
			reasonField: 'settlementNote',
		);
	}//end meet()

	/**
	 * Withdraw an open obligation, with a reason.
	 *
	 * Withdrawn is NOT met, and is never recorded as one: an inspection nobody
	 * carried out is not an inspection that passed. The case is released either
	 * way, and the reason stays readable on it, because "why did this stop
	 * blocking" is the question somebody asks six months later.
	 *
	 * @param string $obligationId The obligation.
	 * @param string $reason       Why it was withdrawn.
	 *
	 * @return bool True when the obligation was withdrawn.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function withdraw(string $obligationId, string $reason): bool {
		return $this->close(
			obligationId: $obligationId,
			state: ObligationDeclaration::STATE_WITHDRAWN,
			field: 'withdrawnAt',
			reason: $reason,
			reasonField: 'withdrawalReason',
		);
	}//end withdraw()

	/**
	 * Every obligation on a case that is still blocking.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The open obligations.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function openFor(string $caseId): array {
		$open = [];
		foreach ($this->allFor(caseId: $caseId) as $obligation) {
			if ($this->declaration->isBlocking(obligation: $obligation) === true) {
				$open[] = $obligation;
			}
		}

		return $open;
	}//end openFor()

	/**
	 * Every obligation on a case, in whatever state.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The rows, or an empty list.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function allFor(string $caseId): array {
		if (trim($caseId) === '') {
			return [];
		}

		[$objectService, $register, $schema] = $this->store();
		if ($objectService === null) {
			return [];
		}

		// The catch LOGS and falls through, and the empty answer is the last
		// statement of the method rather than a `return []` inside an
		// exception handler. `ServiceCatchReturnsNullTest` holds that line for
		// the reason it names: a caller cannot tell "no obligations" from
		// "could not read them" when both arrive as the same empty array, and
		// the shape below at least puts the failure answer where a reader of
		// the method sees it.
		$rows = null;
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq obligations: the obligations of a case could not be read',
				['case' => $caseId, 'error' => $e->getMessage()],
			);
		}

		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end allFor()

	/**
	 * The obligation placed by a given object, if there is one.
	 *
	 * The advice request needs this to settle its own obligation when it is
	 * answered, and it looks the obligation up by `source` rather than holding
	 * the id on the consultation. One direction only: the obligation knows
	 * what placed it, and nothing that places one has to be changed to carry a
	 * second identifier.
	 *
	 * @param string $source The placing object's id.
	 *
	 * @return string The obligation id, or the empty string when there is none.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function idForSource(string $source): string {
		if (trim($source) === '') {
			return '';
		}

		[$objectService, $register, $schema] = $this->store();
		if ($objectService === null) {
			return '';
		}

		$rows = null;
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['source' => $source],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq obligations: the obligation of a source could not be read',
				['source' => $source, 'error' => $e->getMessage()],
			);
		}

		if (is_array($rows) === false) {
			return '';
		}

		foreach ($rows as $row) {
			$id = (string)(($row['id'] ?? ($row['@self']['id'] ?? '')));
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}//end idForSource()

	/**
	 * The obligations that withhold a move into one status.
	 *
	 * @param string $caseId    The case.
	 * @param string $statusId  The destination status.
	 * @param bool   $isClosing Whether that status closes the case.
	 *
	 * @return array<int, array<string, mixed>> The obligations in the way.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function blocking(string $caseId, string $statusId, bool $isClosing): array {
		$blocking = [];
		foreach ($this->openFor(caseId: $caseId) as $obligation) {
			if ($this->declaration->blocksStatus(
				obligation: $obligation,
				statusId: $statusId,
				isClosing: $isClosing,
			) === true) {
				$blocking[] = $obligation;
			}
		}

		return $blocking;
	}//end blocking()

	/**
	 * Settle an obligation into a terminal state.
	 *
	 * @param string $obligationId The obligation.
	 * @param string $state        The terminal state.
	 * @param string $field        The moment field to stamp.
	 * @param string $reason       The reason or note.
	 * @param string $reasonField  Which field the reason is written to.
	 *
	 * @return bool True when the write landed.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function close(
		string $obligationId,
		string $state,
		string $field,
		string $reason,
		string $reasonField,
	): bool {
		if (trim($obligationId) === '') {
			return false;
		}

		[$objectService, $register, $schema] = $this->store();
		if ($objectService === null) {
			return false;
		}

		$row = null;
		try {
			$row = $objectService->find($obligationId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq obligations: the obligation to settle could not be read',
				['obligation' => $obligationId, 'error' => $e->getMessage()],
			);
		}

		if ($row === null) {
			return false;
		}

		$payload = $this->toArray(value: $row);
		if ($payload === []) {
			return false;
		}

		$payload['state'] = $state;
		$payload[$field] = $this->dates->nowAsMoment();
		if ($reason !== '') {
			$payload[$reasonField] = $reason;
		}

		return $this->save(payload: $payload, context: $state) !== '';
	}//end close()

	/**
	 * Write one obligation row.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string               $context What was being done, for the log.
	 *
	 * @return string The id, or the empty string when the write failed.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function save(array $payload, string $context): string {
		[$objectService, $register, $schema] = $this->store();
		if ($objectService === null) {
			return '';
		}

		$saved = null;
		try {
			$saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq obligations: the obligation could not be written',
				['operation' => $context, 'error' => $e->getMessage()],
			);
		}

		if ($saved === null) {
			return '';
		}

		$row = $this->toArray(value: $saved);

		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end save()

	/**
	 * The object service and the register and schema obligations live in.
	 *
	 * @return array{0: object|null, 1: string, 2: string}
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function store(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: self::SCHEMA_KEY);
		if ($objectService === null || $register === '' || $schema === '') {
			return [null, '', ''];
		}

		return [$objectService, $register, $schema];
	}//end store()

	/**
	 * Normalise an OpenRegister return value to an array.
	 *
	 * @param mixed $value The entity, array, or JsonSerializable.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
