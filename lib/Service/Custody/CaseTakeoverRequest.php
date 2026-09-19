<?php

/**
 * Asking the holder of a case for it, and the answer they give.
 *
 * A case nobody holds is CLAIMED, and claiming needs nobody's permission; that
 * is `case-claim-action` and it stops exactly where this begins. A case
 * somebody holds needs that person's answer, and the difference is not
 * politeness: the holder knows something the asker does not, which is why a
 * refusal carries a reason (D-3).
 *
 * A request nobody answers escalates to the unit holding the case rather than
 * expiring in silence (D-4). A request that times out quietly teaches people
 * not to use it, and the case where the holder is unavailable is the case where
 * a pull matters most.
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

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The pull: request, accept, refuse, escalate.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseTakeoverRequest {

	use SearchesObjects;

	/**
	 * The refusal when the case cannot be read.
	 *
	 * @var string
	 */
	public const CASE_UNREADABLE = 'takeover-case-unreadable';

	/**
	 * The refusal when the request itself cannot be found.
	 *
	 * @var string
	 */
	public const REQUEST_UNKNOWN = 'takeover-unknown';

	/**
	 * The refusal when the request has already been answered.
	 *
	 * @var string
	 */
	public const ALREADY_ANSWERED = 'takeover-already-answered';

	/**
	 * The refusal when a refusal arrives without a reason.
	 *
	 * @var string
	 */
	public const REASON_MISSING = 'takeover-reason-missing';

	/**
	 * The refusal when the asker already holds the case.
	 *
	 * @var string
	 */
	public const ALREADY_YOURS = 'takeover-already-yours';

	/**
	 * The refusal when the record cannot be written.
	 *
	 * @var string
	 */
	public const UNWRITABLE = 'takeover-unwritable';

	/**
	 * How long the holder has to answer, when the case type declares nothing.
	 *
	 * @var int
	 */
	public const DEFAULT_ANSWER_DAYS = 3;

	/**
	 * How many requests one read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param CaseCustodyChain  $custody         The chain the answer writes into.
	 * @param EngineTaskGateway $tasks           Carries the request to the holder.
	 * @param LoggerInterface   $logger          Records every request and every answer.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseCustodyChain $custody,
		private readonly EngineTaskGateway $tasks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask the holder for a case.
	 *
	 * @param string $caseId      The case being asked for.
	 * @param string $requestedBy Who wants it.
	 * @param string $reason      Why they should have it.
	 *
	 * @return array<string, mixed> The request as stored.
	 *
	 * @throws RefusedException When the case cannot be read, the asker already holds it, or the record cannot be written.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function request(string $caseId, string $requestedBy, string $reason): array {
		$caseId = trim($caseId);
		$requestedBy = trim($requestedBy);
		$reason = trim($reason);

		if ($reason === '') {
			throw new RefusedException(
				rule: self::REASON_MISSING,
				sentence: 'Say why you should have the case, so the holder can answer.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$case = $this->requireCase(caseId: $caseId);
		$holder = trim((string)($case['assignee'] ?? ''));
		$unit = trim((string)($case['assignedGroup'] ?? ''));

		$open = $this->custody->openHoldingFor(caseId: $caseId);
		if ($open !== null) {
			$unit = trim((string)($open['organisationUnit'] ?? $unit));
			$holder = trim((string)($open['handler'] ?? $holder));
		}

		if ($holder !== '' && $holder === $requestedBy) {
			throw new RefusedException(
				rule: self::ALREADY_YOURS,
				sentence: 'You already hold this case, so there is nobody to ask.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$now = (new DateTimeImmutable())->format('c');
		$record = $this->write(
			record: [
				'caseId' => $caseId,
				'requestedBy' => $requestedBy,
				'holder' => $holder,
				'holdingUnit' => $unit,
				'reason' => $reason,
				'status' => 'pending',
				'requestedAt' => $now,
			],
			uuid: null,
		);

		$record['taskId'] = $this->raiseTask(record: $record, case: $case);
		if ($record['taskId'] !== '') {
			$record = $this->write(record: $record, uuid: $this->uuidOf(row: $record));
		}

		$this->logger->info(
			'Dossiq takeover: a case was asked for',
			['caseId' => $caseId, 'requestedBy' => $requestedBy, 'holder' => $holder, 'unit' => $unit],
		);

		return $record;
	}//end request()

	/**
	 * The holder accepts: the case moves to the asker and a holding opens.
	 *
	 * @param string $takeoverId The request's uuid.
	 * @param string $acceptedBy Who answered.
	 *
	 * @return array<string, mixed> The answered request.
	 *
	 * @throws RefusedException When the request is unknown or already answered.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function accept(string $takeoverId, string $acceptedBy): array {
		$record = $this->requireOpenRequest(takeoverId: $takeoverId);
		$caseId = trim((string)($record['caseId'] ?? ''));
		$asker = trim((string)($record['requestedBy'] ?? ''));
		$unit = trim((string)($record['holdingUnit'] ?? ''));

		$this->custody->move(
			caseId: $caseId,
			organisationUnit: $unit,
			handler: $asker,
			reason: trim((string)($record['reason'] ?? '')),
			movedBy: trim($acceptedBy),
		);

		$case = $this->requireCase(caseId: $caseId);
		$this->writeCase(case: $case, changes: ['assignee' => $asker]);

		$record['status'] = 'accepted';
		$record['answeredBy'] = trim($acceptedBy);
		$record['answeredAt'] = (new DateTimeImmutable())->format('c');

		return $this->write(record: $record, uuid: $this->uuidOf(row: $record));
	}//end accept()

	/**
	 * The holder refuses: the case stays, and the reason is on the record.
	 *
	 * @param string $takeoverId The request's uuid.
	 * @param string $reason     Why the holder is keeping the case.
	 * @param string $refusedBy  Who answered.
	 *
	 * @return array<string, mixed> The answered request.
	 *
	 * @throws RefusedException When the request is unknown, already answered, or the reason is missing.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function refuse(string $takeoverId, string $reason, string $refusedBy): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RefusedException(
				rule: self::REASON_MISSING,
				sentence: 'A refusal without a reason is not an answer, so nothing was recorded.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$record = $this->requireOpenRequest(takeoverId: $takeoverId);
		$record['status'] = 'refused';
		$record['refusalReason'] = $reason;
		$record['answeredBy'] = trim($refusedBy);
		$record['answeredAt'] = (new DateTimeImmutable())->format('c');

		$this->logger->info(
			'Dossiq takeover: the holder kept the case',
			['caseId' => ($record['caseId'] ?? ''), 'refusedBy' => $refusedBy],
		);

		return $this->write(record: $record, uuid: $this->uuidOf(row: $record));
	}//end refuse()

	/**
	 * Move every unanswered request past its period to the holding unit.
	 *
	 * The status becomes `escalated`, which is still OPEN: the question moved,
	 * it was not answered. A request that simply expired would be indexed the
	 * same as one somebody refused, and the two are not the same fact.
	 *
	 * @param string $now The moment to measure from, in ISO 8601, or empty for now.
	 *
	 * @return array<int, array<string, mixed>> The requests that escalated.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function escalateOverdue(string $now = ''): array {
		$moment = $this->instant(value: $now) ?? new DateTimeImmutable();
		$escalated = [];

		foreach ($this->pending() as $record) {
			$asked = $this->instant(value: (string)($record['requestedAt'] ?? ''));
			if ($asked === null) {
				continue;
			}

			$days = $this->answerPeriodDays(caseId: (string)($record['caseId'] ?? ''));
			$due = $asked->add(new DateInterval('P' . $days . 'D'));
			if ($due > $moment) {
				continue;
			}

			$record['status'] = 'escalated';
			$record['escalatedAt'] = $moment->format('c');
			$escalated[] = $this->write(record: $record, uuid: $this->uuidOf(row: $record));

			$unit = trim((string)($record['holdingUnit'] ?? ''));
			$taskId = trim((string)($record['taskId'] ?? ''));
			if ($taskId !== '' && $unit !== '') {
				$this->tasks->reassign(taskId: $taskId, assignee: $unit, actor: null);
			}
		}

		return $escalated;
	}//end escalateOverdue()

	/**
	 * Every request on a case, newest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function onCase(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		$rows = $this->rows(filters: ['caseId' => $caseId, '_limit' => self::PAGE_SIZE]);
		usort(
			$rows,
			static function (array $left, array $right): int {
				return (((string)($right['requestedAt'] ?? '')) <=> ((string)($left['requestedAt'] ?? '')));
			},
		);

		return $rows;
	}//end onCase()

	/**
	 * The requests nobody has answered yet.
	 *
	 * @return array<int, array<string, mixed>> The pending requests.
	 */
	private function pending(): array {
		return $this->rows(filters: ['status' => 'pending', '_limit' => self::PAGE_SIZE]);
	}//end pending()

	/**
	 * How long the holder has to answer, for this case's type.
	 *
	 * @param string $caseId The case.
	 *
	 * @return int The number of days.
	 */
	private function answerPeriodDays(string $caseId): int {
		try {
			$case = $this->requireCase(caseId: $caseId);
		} catch (RefusedException $e) {
			return self::DEFAULT_ANSWER_DAYS;
		}

		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return self::DEFAULT_ANSWER_DAYS;
		}

		try {
			[$objectService, $register] = $this->context();
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			return self::DEFAULT_ANSWER_DAYS;
		}

		$declared = (int)($caseType['takeoverAnswerPeriodDays'] ?? 0);
		if ($declared < 1) {
			return self::DEFAULT_ANSWER_DAYS;
		}

		return $declared;
	}//end answerPeriodDays()

	/**
	 * Raise the engine task that carries the request to the holder.
	 *
	 * @param array<string, mixed> $record The stored request.
	 * @param array<string, mixed> $case   The case being asked for.
	 *
	 * @return string The engine task uuid, or an empty string when the engine did not take it.
	 */
	private function raiseTask(array $record, array $case): string {
		$holder = trim((string)($record['holder'] ?? ''));
		$unit = trim((string)($record['holdingUnit'] ?? ''));
		$title = 'A colleague is asking for ' . trim((string)($case['title'] ?? 'this case'));

		try {
			return $this->tasks->mirrorImport(
				task: [
					'id' => $this->uuidOf(row: $record),
					'title' => $title,
					'description' => trim((string)($record['reason'] ?? '')),
					'status' => 'available',
					'assignee' => $holder,
					'assigneeGroup' => $unit,
					'priority' => 'normal',
				],
				caseId: trim((string)($record['caseId'] ?? '')),
				actor: trim((string)($record['requestedBy'] ?? '')),
			);
		} catch (Throwable $e) {
			// The request is already recorded. A task the engine would not
			// take is a delivery failure, not a reason to lose the question.
			$this->logger->warning(
				'Dossiq takeover: the request was recorded but the engine did not take the task',
				['caseId' => ($record['caseId'] ?? ''), 'exception' => $e->getMessage()],
			);

			return '';
		}
	}//end raiseTask()

	/**
	 * A request that is still open, or a refusal.
	 *
	 * @param string $takeoverId The request's uuid.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @throws RefusedException When it is unknown or already answered.
	 */
	private function requireOpenRequest(string $takeoverId): array {
		$takeoverId = trim($takeoverId);

		try {
			[$objectService, $register] = $this->context();
			$record = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_takeover_schema'),
				id: $takeoverId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::REQUEST_UNKNOWN,
				sentence: 'We could not read that request, so nothing was changed.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($record === null) {
			throw new RefusedException(
				rule: self::REQUEST_UNKNOWN,
				sentence: 'That request could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$status = trim((string)($record['status'] ?? ''));
		if ($status !== 'pending' && $status !== 'escalated') {
			throw new RefusedException(
				rule: self::ALREADY_ANSWERED,
				sentence: 'That request was already answered, so nothing was changed.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $record;
	}//end requireOpenRequest()

	/**
	 * The stored case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: trim($caseId),
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so nothing was asked.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so nothing was asked.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * Apply changes to the stored case.
	 *
	 * @param array<string, mixed> $case    The case as it was read.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 */
	private function writeCase(array $case, array $changes): void {
		$caseId = $this->uuidOf(row: $case);
		if ($caseId === '' || $changes === []) {
			return;
		}

		$payload = array_merge($case, $changes);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq takeover: the case seat could not be written',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end writeCase()

	/**
	 * Read takeover rows under a filter.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $filters): array {
		try {
			[$objectService, $register] = $this->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_takeover_schema'),
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq takeover: the requests could not be read',
				['exception' => $e->getMessage()],
			);

			return [];
		}
	}//end rows()

	/**
	 * Store a request, new or existing.
	 *
	 * @param array<string, mixed> $record The request.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function write(array $record, ?string $uuid): array {
		unset($record['@self'], $record['id'], $record['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_takeover_schema'),
				object: $record,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The request could not be recorded, so nobody was asked.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($saved === null) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The request could not be recorded, so nobody was asked.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $saved;
	}//end write()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * A moment, or null when the value is empty or unreadable.
	 *
	 * @param string $value The candidate.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function instant(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()

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
