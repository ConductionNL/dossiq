<?php

/**
 * Dossiq TermijnService.
 *
 * Server-authoritative service for the AWB termijnbewaking engine. Owns
 * the TermijnInstance lifecycle: create, get, update, complete. Resolves
 * the active TermijnDefinitie for a zaaktype (version-aware) and binds it
 * to a zaak on creation. Writes immutable TermijnGebeurtenis events for
 * every state change.
 *
 * Money / day amounts: all *daily impact* values are integers (days);
 * money values live on DwangsomBerekening / DwangsomUitbetaling and use
 * integer EUR cents (ADR-031).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-authoritative TermijnInstance lifecycle.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TermijnService {
	use SearchesObjects;

	/**
	 * What a handler reads when a term of each kind starts.
	 *
	 * Four kinds, four sentences, because "Termijn gestart" on all four tells a
	 * handler nothing about which clock moved, and a case carries up to four of
	 * them at once.
	 *
	 * @var array<string, string>
	 */
	private const TERM_START_SENTENCES = [
		TermKind::STATUTORY => 'Wettelijke termijn gestart',
		TermKind::PLANNED => 'Geplande einddatum vastgelegd',
		TermKind::INTERNAL => 'Interne streefdatum vastgelegd',
		TermKind::PHASE => 'Fasetermijn gestart',
	];

	/**
	 * Per-request TermijnDefinitie cache keyed by zaaktype.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $definitieCache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 * @param TermijnTimerService|null $timerService Engine timer mapping (optional while the engine rolls out).
	 * @param CaseTimeline|null $timeline The one seam that writes a timeline entry.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?CaseTimeline $timeline = null,
	) {
	}//end __construct()

	/**
	 * Create a new TermijnInstance for a zaak.
	 *
	 * Resolves the active TermijnDefinitie for the zaaktype, computes
	 * einddatumBerekend = startDatum + standaardDuurDagen, persists the
	 * instance, and writes a `start` TermijnGebeurtenis. Throws if no
	 * matching definition exists (REQ-TERM-001-A).
	 *
	 * @param string $caseId The case id.
	 * @param string $caseType The zaaktype SLUG. A `case` object carries its
	 *        case type as a uuid, so a caller holding one must convert it
	 *        through {@see CaseTypeSlugResolver} first — a uuid matches no
	 *        definition and the term silently never starts.
	 * @param DateTimeImmutable|null $startDate Optional start (defaults to now).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NoTermijnDefinitieException When no TermijnDefinitie matches the zaaktype.
	 * @throws RuntimeException When the instance cannot be persisted.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function createTermijnInstance(string $caseId, string $caseType, ?DateTimeImmutable $startDate = null): array {
		$startDate = ($startDate ?? new DateTimeImmutable());
		$definitie = $this->getTermijnDefinitie(caseType: $caseType);
		if ($definitie === null) {
			// A DISTINCT type, because this is the one refusal a caller can
			// act on and the one that must not be swallowed at debug level:
			// it means no statutory clock started for this case at all.
			throw new NoTermijnDefinitieException(
				message: 'No active TermijnDefinitie configured for zaaktype "' . $caseType . '" (REQ-TERM-001-A)'
			);
		}

		$durationDays = (int)($definitie['standardDurationDays'] ?? 0);
		$endDate = $startDate->modify('+' . $durationDays . ' days')->format('Y-m-d');

		$instance = [
			'case' => $caseId,
			'deadlineDefinition' => (string)($definitie['id'] ?? ''),
			// The kind this instance carries. A term created here is the one the
			// Awb sets and the citizen is told about, and every instance written
			// before the four kinds existed is one of these too, which is why
			// {@see TermKind::ofInstance()} reads an absent kind as statutory.
			'kind' => TermKind::STATUTORY,
			'startDate' => $startDate->format('Y-m-d\TH:i:sP'),
			'endDateCalculated' => $endDate,
			'endDateCurrent' => $endDate,
			'status' => 'lopend',
			'countExtensions' => 0,
			'notificatiesVerstuurd' => [],
		];

		$saved = $this->save(schemaConfigKey: 'termijn_instance_schema', object: $instance);
		if ($saved === null) {
			throw new RuntimeException(
				'Failed to persist TermijnInstance for zaak "' . $caseId . '" (persistence unavailable)'
			);
		}

		$this->recordEvent(
			termInstanceId: (string)($saved['id'] ?? ''),
			type: 'start',
			basis: (string)($definitie['legalBasis'] ?? 'AWB 4:13'),
			rationale: 'Termijn gestart bij zaak-aanmaak',
			daysImpact: $durationDays,
			moment: $startDate,
		);

		return ($this->armEngineTimer(instance: $saved, definitie: $definitie) ?? $saved);
	}//end createTermijnInstance()

	/**
	 * Arm the engine timer for a freshly created instance and store its
	 * uuid as `engineTimerId` (REQ-TOT-001). A missing engine degrades to
	 * a logged no-op inside {@see TermijnTimerService}; the instance then
	 * simply carries no timer until the repair step re-arms it.
	 *
	 * @param array<string, mixed> $instance The saved TermijnInstance.
	 * @param array<string, mixed> $definitie The resolved TermijnDefinitie.
	 *
	 * @return array<string, mixed>|null The instance carrying `engineTimerId`, or null.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	private function armEngineTimer(array $instance, array $definitie): ?array {
		if ($this->timerService === null) {
			return null;
		}

		$timerId = $this->timerService->armBeslistermijn(instance: $instance, definitie: $definitie);
		if ($timerId === null) {
			return null;
		}

		return $this->updateTermijnInstance(
			termInstanceId: (string)($instance['id'] ?? ''),
			patch: ['engineTimerId' => $timerId]
		);
	}//end armEngineTimer()

	/**
	 * Get TermijnInstance by id.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnInstance(string $termInstanceId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $termInstanceId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermijnService.getTermijnInstance failed',
				['id' => $termInstanceId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end getTermijnInstance()

	/**
	 * Fetch the active TermijnInstance bound to a zaak (latest by start).
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnInstanceForZaak(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['case' => $caseId]);
		} catch (\Throwable $e) {
			return null;
		}

		if (count($rows) === 0) {
			return null;
		}

		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows[0];
	}//end getTermijnInstanceForZaak()

	/**
	 * Every TermijnInstance bound to a case, newest first.
	 *
	 * {@see getTermijnInstanceForZaak()} answers the ONE latest instance, which
	 * was the right answer while a case had one clock. A case now carries a
	 * statutory term, a planned end, an internal target and a phase term, and a
	 * caller that wants all four cannot get them by asking for the latest four
	 * times. This is the same query without the `[0]`.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<int, array<string, mixed>> The instances, newest start first.
	 *
	 * @throws RefusedException When the store could not be asked.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function instancesForCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $caseId === '') {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId]
			);
		} catch (\Throwable $e) {
			// NOT an empty list. A case with no clocks and a case whose clocks
			// could not be read are opposite facts, and the second rendered as
			// the first tells a handler there is no deadline.
			$this->logger->warning(
				'TermijnService.instancesForCase lookup failed, so the read is refused',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			throw new RefusedException(
				rule: 'term-instances-unreadable',
				sentence: 'The terms on this case could not be read.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try

		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows;
	}//end instancesForCase()

	/**
	 * Persist a term instance of any kind.
	 *
	 * The ONE writer of a TermijnInstance row. A planned end, an internal
	 * target and a phase term are computed elsewhere, because their end dates
	 * reach the working calendar and this file's statutory path does not yet;
	 * they are written here, so there is still one place that knows which
	 * register and which schema a term instance lives in.
	 *
	 * @param array<string, mixed> $instance The finished row.
	 *
	 * @return array<string, mixed>|null The stored instance, or null when the store refused.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function saveTermInstance(array $instance): ?array {
		$saved = $this->save(schemaConfigKey: 'termijn_instance_schema', object: $instance);
		if ($saved === null) {
			return null;
		}

		if (isset($instance['id']) === false) {
			$this->recordTermStartOnTimeline(instance: $saved);
		}

		return $saved;
	}//end saveTermInstance()

	/**
	 * Put the start of a non-statutory term on the case's timeline.
	 *
	 * WHY THIS IS A SECOND SEAM RATHER THAN A SECOND CALLER OF `recordEvent()`.
	 * A planned end, an internal target and a phase term are written straight
	 * to the instance schema and write NO TermijnGebeurtenis at all, by design:
	 * they are not statutory clocks and nothing about them has a legal basis to
	 * record. So the event funnel never sees them, and a handler reading the
	 * timeline would see the statutory clock start and nothing about the phase
	 * clock that actually governs their week. Giving them an event row instead
	 * would put four rows in a register that means "Awb event".
	 *
	 * ONLY ON A CREATE. `bindStatutory()` re-binds an existing instance through
	 * `updateTermijnInstance()`, so this method is reached only when a term is
	 * first written, and an `id` on the way in is the one thing that separates
	 * the two.
	 *
	 * @param array<string, mixed> $instance The stored term instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	private function recordTermStartOnTimeline(array $instance): void {
		if ($this->timeline === null) {
			return;
		}

		$caseId = trim((string)($instance['case'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$kind = (string)($instance['kind'] ?? TermKind::STATUTORY);
		$due = (string)($instance['endDateCurrent'] ?? ($instance['endDateCalculated'] ?? ''));

		$message = self::TERM_START_SENTENCES[$kind] ?? 'Termijn gestart';
		if ($due !== '') {
			$message .= ', uiterlijk ' . $due;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $message,
			fields: [
				'event' => 'start',
				'term' => $kind,
				'occurredAt' => (string)($instance['startDate'] ?? ''),
				'dueAt' => $due,
				'startedAt' => (string)($instance['startDate'] ?? ''),
				'basis' => '',
				'termijnId' => (string)($instance['id'] ?? ''),
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordTermStartOnTimeline()

	/**
	 * Update a TermijnInstance (partial; merged on top of existing).
	 *
	 * @param string $termInstanceId Instance id.
	 * @param array<string, mixed> $patch Partial patch.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function updateTermijnInstance(string $termInstanceId, array $patch): ?array {
		$current = $this->getTermijnInstance(termInstanceId: $termInstanceId);
		if ($current === null) {
			return null;
		}

		$merged = array_merge($current, $patch);
		$merged['id'] = $termInstanceId;
		return $this->save(schemaConfigKey: 'termijn_instance_schema', object: $merged);
	}//end updateTermijnInstance()

	/**
	 * Resolve the active TermijnDefinitie for a zaaktype.
	 *
	 * Version-aware: returns the definition with the latest validFrom that
	 * is <= today, where validUntil is null or > today.
	 *
	 * @param string $caseType Zaaktype slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnDefinitie(string $caseType): ?array {
		if (isset($this->definitieCache[$caseType]) === true) {
			return $this->definitieCache[$caseType];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseType]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermijnService.getTermijnDefinitie lookup failed',
				['caseType' => $caseType, 'error' => $e->getMessage()]
			);
			return null;
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		$active = $this->filterActiveDefinities(rows: $rows, today: $today);

		if (count($active) === 0) {
			return null;
		}

		usort(
			$active,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['validFrom'] ?? ''), (string)($a['validFrom'] ?? ''))
		);

		$this->definitieCache[$caseType] = $active[0];
		return $active[0];
	}//end getTermijnDefinitie()

	/**
	 * Keep the TermijnDefinitie rows whose validity window covers today.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate definitions.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return array<int, array<string, mixed>> The definitions valid today.
	 */
	private function filterActiveDefinities(array $rows, string $today): array {
		$active = [];
		foreach ($rows as $row) {
			$validFrom = (string)($row['validFrom'] ?? '1970-01-01');
			$validUntil = (string)($row['validUntil'] ?? '');
			if ($validFrom <= $today && ($validUntil === '' || $validUntil >= $today)) {
				$active[] = $row;
			}
		}//end foreach

		return $active;
	}//end filterActiveDefinities()

	/**
	 * Mark a TermijnInstance as completed.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param DateTimeImmutable|null $voltooiDatum When completed (default now).
	 * @param string $documentLink Optional document ref.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
	 */
	public function markTermijnCompleted(
		string $termInstanceId,
		?DateTimeImmutable $voltooiDatum = null,
		string $documentLink = '',
	): ?array {
		$voltooiDatum = ($voltooiDatum ?? new DateTimeImmutable());

		$updated = $this->updateTermijnInstance(
			termInstanceId: $termInstanceId,
			patch: ['status' => 'completed', 'voltooiDatum' => $voltooiDatum->format('Y-m-d')]
		);

		if ($updated !== null) {
			$this->recordEvent(
				termInstanceId: $termInstanceId,
				type: 'voltooi',
				basis: 'AWB 4:13',
				rationale: 'Termijn voltooid door beschikking',
				daysImpact: 0,
				moment: $voltooiDatum,
				documentLink: $documentLink,
			);

			// Completion cancels every open timer of the instance, in the
			// same operation that made the term terminal (REQ-TOT-001).
			$this->timerService?->cancelForInstance(
				instanceId: $termInstanceId,
				reason: 'Termijn voltooid door beschikking'
			);
		}

		return $updated;
	}//end markTermijnCompleted()

	/**
	 * Append an immutable TermijnGebeurtenis row.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $type Event type.
	 * @param string $basis Legal basis.
	 * @param string $rationale Reason.
	 * @param int $daysImpact Days impact.
	 * @param DateTimeImmutable|null $moment When (default now).
	 * @param string $documentLink Optional document ref.
	 * @param string $actor Optional actor (default 'system').
	 * @param array<int, string> $items What was asked for, or what came in. Awb 4:5
	 *        joins asking to suspending, so the record that carries the suspension
	 *        carries what was asked in the same row rather than in a second one.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function recordEvent(
		string $termInstanceId,
		string $type,
		string $basis,
		string $rationale,
		int $daysImpact,
		?DateTimeImmutable $moment = null,
		string $documentLink = '',
		string $actor = 'system',
		array $items = [],
	): ?array {
		$moment = ($moment ?? new DateTimeImmutable());
		$event = [
			'deadlineInstance' => $termInstanceId,
			'type' => $type,
			'moment' => $moment->format('Y-m-d\TH:i:sP'),
			'actor' => $actor,
			'basis' => $basis,
			'rationale' => $rationale,
			'daysImpact' => $daysImpact,
		];
		if ($documentLink !== '') {
			$event['documentLink'] = $documentLink;
		}

		if (count($items) > 0) {
			$event['items'] = array_values($items);
		}

		$saved = $this->save(schemaConfigKey: 'termijn_gebeurtenis_schema', object: $event);
		if ($saved === null) {
			return null;
		}

		$this->recordEventOnTimeline(
			termInstanceId: $termInstanceId,
			type: $type,
			basis: $basis,
			rationale: $rationale,
			moment: $moment,
		);

		return $saved;
	}//end recordEvent()

	/**
	 * Put a term event on the case's timeline.
	 *
	 * WHY EVERY TERM EVENT ARRIVES HERE. Thirteen call sites across the app
	 * write a TermijnGebeurtenis, and every one of them does it through
	 * {@see self::recordEvent()}: the start, the pause and the resume, the
	 * extension, the overrun, the completion, the three aanvullingsverzoek
	 * events, both objection events and both dwangsom events. One write here
	 * puts all of them on the timeline without a second file knowing that a
	 * timeline exists, which is what keeps the aanvullingsverzoek's own seam
	 * untouched.
	 *
	 * THE EVENT ROW HAS NO CASE ON IT. A TermijnGebeurtenis names its
	 * instance, and the instance names the case, so the instance is read back
	 * to reach the case, the term's kind and its current end date. An instance
	 * that cannot be read leaves the event stored and the timeline line
	 * missing, which is the same soft failure every other writer takes.
	 *
	 * THE SENTENCE IS THE RATIONALE, NOT THE TYPE. Every caller already
	 * supplies a Dutch sentence saying what happened, and `$type` is an
	 * identifier (`pause-expired`, `information-requested`) that has no
	 * business being read by a handler.
	 *
	 * @param string                 $termInstanceId The instance the event hangs on.
	 * @param string                 $type           The event type, as stored.
	 * @param string                 $basis          The legal basis.
	 * @param string                 $rationale      The sentence a handler reads.
	 * @param DateTimeImmutable|null $moment         When it happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	private function recordEventOnTimeline(
		string $termInstanceId,
		string $type,
		string $basis,
		string $rationale,
		?DateTimeImmutable $moment,
	): void {
		if ($this->timeline === null || $termInstanceId === '') {
			return;
		}

		$instance = $this->getTermijnInstance(termInstanceId: $termInstanceId);
		$caseId = trim((string)($instance['case'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$message = trim($rationale);
		if ($message === '') {
			$message = 'Termijngebeurtenis vastgelegd';
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $message,
			fields: [
				'event' => $type,
				'term' => (string)($instance['kind'] ?? TermKind::STATUTORY),
				'occurredAt' => ($moment ?? new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
				'dueAt' => (string)($instance['endDateCurrent'] ?? ($instance['endDateCalculated'] ?? '')),
				'startedAt' => (string)($instance['startDate'] ?? ''),
				'basis' => $basis,
				'termijnId' => $termInstanceId,
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordEventOnTimeline()

	/**
	 * Persist an object to a configured schema.
	 *
	 * @param string $schemaConfigKey The schema config key (e.g. 'termijn_instance_schema').
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function save(string $schemaConfigKey, array $object): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TermijnService persist failed',
				['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end save()
}//end class
