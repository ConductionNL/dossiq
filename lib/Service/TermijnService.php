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
use OCA\Dossiq\Service\Timeline\TermEventEntry;
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
	 * @param TermEventEntry|null $termEntry The timeline entry a term event writes.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermEventEntry $termEntry = null,
		private readonly ?CaseDateNormaliser $dates = null,
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
	 * @param array<string, mixed>|null $resolution The resolution a caller already
	 *        made, as {@see \OCA\Dossiq\Service\Term\TermResolution::resolve()}
	 *        answers it. Passed in rather than made here because resolving needs
	 *        the case's organisation, service and priority, which this method is
	 *        never given; absent, the case type's own term is used exactly as
	 *        before, which is what every caller written before this did.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NoTermijnDefinitieException When no TermijnDefinitie matches the zaaktype.
	 * @throws RuntimeException When the instance cannot be persisted.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
	 */
	public function createTermijnInstance(
		string $caseId,
		string $caseType,
		?DateTimeImmutable $startDate = null,
		?array $resolution = null,
	): array {
		$startDate = ($startDate ?? new DateTimeImmutable());
		$definitie = (($resolution['definition'] ?? null) ?? $this->getTermijnDefinitie(caseType: $caseType));
		if ($definitie === null) {
			// A DISTINCT type, because this is the one refusal a caller can
			// act on and the one that must not be swallowed at debug level:
			// it means no statutory clock started for this case at all.
			throw new NoTermijnDefinitieException(
				message: 'No active TermijnDefinitie configured for zaaktype "' . $caseType . '" (REQ-TERM-001-A)'
			);
		}

		$durationDays = (int)($definitie['standardDurationDays'] ?? 0);
		$computed = $this->endDateFor(start: $startDate, days: $durationDays, definitie: $definitie);

		// THE ALGEMENE TERMIJNENWET ROLL. `+N days` on its own lands a third
		// of dossiq's terms on a Saturday, a Sunday or a recognised holiday,
		// which Awt art. 1 says must move to the next ordinary day.
		//
		// 🔑 ONE ROLL FOR THE WHOLE APP, AND IT IS THE TIMER SERVICE'S.
		// `rollTermEndFor()` is the call every other term site makes, it reads
		// the declared flag itself, and it refuses when a term names a
		// calendar the engine cannot resolve. A second roll here read the same
		// flag with the OPPOSITE default for a few hours, which is how two
		// implementations of one statutory rule start answering different
		// dates for the same case.
		//
		// The armed timer inherits the rolled date without a third rule: it
		// derives its SLA from `endDateCurrent` through
		// {@see TermijnTimerService::slaDaysFor()}, so one computation decides
		// both the stored date and the deadline the engine counts to.
		$computed = ($this->timerService?->rollTermEndFor(date: $computed, definitie: $definitie) ?? $computed);

		$endDate = $computed->format('Y-m-d');

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

		// Which rule produced this term, recorded rather than re-derivable. A
		// term somebody disputes has to be explainable a year later, and the
		// configuration will have changed by then.
		if ($resolution !== null) {
			$instance['resolvedFrom'] = (string)($resolution['resolvedFrom'] ?? '');
			$instance['resolutionSnapshot'] = (array)($resolution['snapshot'] ?? []);
		}

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
	 * The end date of a term, counted in the mode its definition declares.
	 *
	 * 🔴 A DEGRADED WORKING-DAY TERM IS LOGGED, NOT SILENTLY SHORTENED. When
	 * the definition asks for working days and the organisation calendar does
	 * not answer, the fallback counts calendar days, which gives the case a
	 * SHORTER term than it is owed: ten working days is fourteen calendar
	 * days, so the applicant loses four. That is the documented degradation
	 * (D-2, the D-7 posture) and it is stated at warning naming the case type,
	 * because a term nobody can see is short is the failure this whole change
	 * exists to end.
	 *
	 * @param DateTimeImmutable    $start     The day the term starts.
	 * @param int                  $days      The declared duration.
	 * @param array<string, mixed> $definitie The definition.
	 *
	 * @return DateTimeImmutable The end date, before the Awt roll.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	private function endDateFor(DateTimeImmutable $start, int $days, array $definitie): DateTimeImmutable {
		$mode = self::countingModeOf(definitie: $definitie);
		if ($mode !== WorkingDayRoll::MODE_WORKING_DAYS || $this->roll === null) {
			return $start->modify('+' . $days . ' days');
		}

		$computed = $this->roll->endAfter(start: $start, days: $days, mode: $mode);
		if ($computed !== null) {
			return $computed;
		}

		$this->logger->warning(
			'Dossiq termijn: a term declares working days and the organisation calendar did not answer, '
			. 'so its end date was counted in calendar days and the term is SHORTER than it is owed',
			['caseType' => (string)($definitie['caseType'] ?? ''), 'days' => $days]
		);

		return $start->modify('+' . $days . ' days');
	}//end endDateFor()

	/**
	 * The counting mode a definition declares.
	 *
	 * Static, because the timer service asks the same question of the same row
	 * and two readings of one declaration is how the badge and the engine come
	 * to count down to different dates. An absent or unknown value reads as
	 * calendar days: every definition written before this property existed
	 * counts them, and an Awb beslistermijn counts them by law.
	 *
	 * @param array<string, mixed> $definitie The definition.
	 *
	 * @return string One of the two modes.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public static function countingModeOf(array $definitie): string {
		$declared = trim((string)($definitie['countingMode'] ?? ''));

		return ($declared === WorkingDayRoll::MODE_WORKING_DAYS
			? WorkingDayRoll::MODE_WORKING_DAYS
			: WorkingDayRoll::MODE_CALENDAR_DAYS);
	}//end countingModeOf()

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

		// A REWRITE IS NOT A START. `bindStatutory()` re-binds a term that is
		// already running when the case type's fixed end date moves, and a
		// timeline announcing a start each time would report clocks that never
		// started. The row as it was handed in tells the two apart, so the
		// writer is given the fact rather than a verdict and this method keeps
		// no branch of its own.
		$this->termEntry?->recordStart(instance: (array)$saved, requested: $instance);

		return $saved;
	}//end saveTermInstance()

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

		$active = $this->definitionsFor(caseType: $caseType);
		if (count($active) === 0) {
			return null;
		}

		$this->definitieCache[$caseType] = $active[0];
		return $active[0];
	}//end getTermijnDefinitie()

	/**
	 * EVERY active TermijnDefinitie for a zaaktype, newest validFrom first.
	 *
	 * {@see getTermijnDefinitie()} answers the ONE a case type falls back to.
	 * A case type can carry several: one per participating organisation, per
	 * service and per priority, which is what lets one shared case type serve
	 * five municipalities with five agreed norms and no duplication. Choosing
	 * between them is {@see \OCA\Dossiq\Service\Term\TermResolution}'s
	 * job, because the order is a policy and this is the store.
	 *
	 * @param string $caseType The zaaktype slug.
	 *
	 * @return array<int, array<string, mixed>> The active definitions.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
	 */
	public function definitionsFor(string $caseType): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
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
				'TermijnService.definitionsFor lookup failed',
				['caseType' => $caseType, 'error' => $e->getMessage()]
			);
			return [];
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		$active = $this->filterActiveDefinities(rows: $rows, today: $today);

		usort(
			$active,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['validFrom'] ?? ''), (string)($a['validFrom'] ?? ''))
		);

		return $active;
	}//end definitionsFor()

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
	 * @param string $rationale Why the term ended, as the timeline will read it.
	 *        Left empty it reads "Termijn voltooid door beschikking", which is
	 *        what closed every term before another act could. A rebind and a
	 *        merge close one too, and a timeline that calls either a
	 *        beschikking says the case was decided when it was not.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
	 */
	public function markTermijnCompleted(
		string $termInstanceId,
		?DateTimeImmutable $voltooiDatum = null,
		string $documentLink = '',
		string $rationale = '',
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
				// WHY A TERM ENDED IS NOT ALWAYS "door beschikking". A rebind
				// closes a running term to re-arm it against the new case
				// type's definition, and recording that as a decision would put
				// a beschikking in the audit trail of a case that never got
				// one. The default is the old sentence, so every existing
				// caller reads exactly as it did.
				rationale: (trim($rationale) !== '') ? trim($rationale) : 'Termijn voltooid door beschikking',
				daysImpact: 0,
				moment: $voltooiDatum,
				documentLink: $documentLink,
			);

			// Completion cancels every open timer of the instance, in the
			// same operation that made the term terminal (REQ-TOT-001).
			$this->timerService?->cancelForInstance(
				instanceId: $termInstanceId,
				reason: (trim($rationale) !== '') ? trim($rationale) : 'Termijn voltooid door beschikking'
			);
		}

		return $updated;
	}//end markTermijnCompleted()

	/**
	 * Re-arm this case's running terms against another case type's definition.
	 *
	 * 🔴 A REBIND MOVES NO STATUTORY CLOCK, AND THAT IS THE WHOLE RULE. The
	 * case was received on a day, and the Awb term runs from the day it was
	 * received, not from the day somebody noticed it had been filed under the
	 * wrong type. So the new instance keeps the old one's `startDate`, and the
	 * only thing the target definition supplies is the DURATION. Starting the
	 * clock again at the rebind would hand the organisation weeks it is not
	 * entitled to, silently, on every case that was ever refiled.
	 *
	 * 🔑 EXTENSIONS AND SUSPENSIONS TRAVEL AS DAYS, NOT AS DATES. An Awb 4:14
	 * verdaging is "this term is longer by N days", and the days are what
	 * survives a change of definition: carrying the old `endDateCurrent`
	 * forward would carry the old duration with it and quietly ignore the
	 * target's rule. Each carried event is re-recorded on the new instance, so
	 * the trail says why the end date is where it is rather than leaving a
	 * number nobody can account for. D-2's fixture is the test of exactly this:
	 * a 56-day term started 1 June, extended once by 14 days, rebound on 20
	 * June to an 84-day definition, ends on 1 June plus 98 days.
	 *
	 * A target case type with no term definition is NOT an empty answer. The
	 * old instances are left running and the count of them is reported, because
	 * completing a statutory clock that has no successor is how a case silently
	 * stops being watched.
	 *
	 * @param string $caseId       The case whose terms are re-armed.
	 * @param string $caseTypeSlug The TARGET case type, as the slug the term
	 *                             definitions are keyed by. A uuid matches no
	 *                             definition and the re-arm silently does
	 *                             nothing, so callers holding one convert it
	 *                             through {@see CaseTypeSlugResolver} first.
	 * @param string $reason       Why the case was rebound, for the trail.
	 *
	 * @return array{rearmed: int, kept: int, note: string} What happened to the clocks.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function rearmForDefinition(string $caseId, string $caseTypeSlug, string $reason): array {
		$running = [];
		foreach ($this->instancesForCase(caseId: $caseId) as $instance) {
			if ((string)($instance['status'] ?? '') === 'lopend') {
				$running[] = $instance;
			}
		}

		if ($running === []) {
			return ['rearmed' => 0, 'kept' => 0, 'note' => ''];
		}

		$definitie = $this->getTermijnDefinitie(caseType: $caseTypeSlug);
		if ($definitie === null) {
			$this->logger->warning(
				'TermijnService.rearmForDefinition: the target case type has no active term definition, '
					. 'so the running terms were left on the definition they started under',
				['case' => $caseId, 'caseType' => $caseTypeSlug, 'running' => count($running)]
			);

			return [
				'rearmed' => 0,
				'kept' => count($running),
				'note' => 'The target case type has no active term definition, so this case\'s running terms '
					. 'were left as they are rather than closed with nothing to replace them.',
			];
		}

		$rearmed = 0;
		foreach ($running as $instance) {
			if ($this->rearmOne(instance: $instance, caseId: $caseId, caseTypeSlug: $caseTypeSlug, reason: $reason) === true) {
				$rearmed++;
			}
		}

		return [
			'rearmed' => $rearmed,
			'kept' => (count($running) - $rearmed),
			'note' => '',
		];
	}//end rearmForDefinition()

	/**
	 * Close one running term and open its successor on the same start date.
	 *
	 * @param array<string, mixed> $instance     The running instance.
	 * @param string               $caseId       The case.
	 * @param string               $caseTypeSlug The target case type's slug.
	 * @param string               $reason       Why the case was rebound.
	 *
	 * @return boolean True when the successor was created.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function rearmOne(array $instance, string $caseId, string $caseTypeSlug, string $reason): bool {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '') {
			return false;
		}

		$carried = $this->carriedEvents(termInstanceId: $instanceId);
		$startDate = $this->startOf(instance: $instance);

		try {
			$successor = $this->createTermijnInstance(
				caseId: $caseId,
				caseType: $caseTypeSlug,
				startDate: $startDate
			);
		} catch (NoTermijnDefinitieException | RuntimeException $e) {
			// The successor is created BEFORE the old one is closed, so a
			// failure here leaves the case with the clock it already had
			// rather than with none at all.
			$this->logger->error(
				'TermijnService.rearmForDefinition: the successor term could not be created, '
					. 'so the running term was left alone',
				['case' => $caseId, 'instance' => $instanceId, 'error' => $e->getMessage()]
			);

			return false;
		}

		$this->markTermijnCompleted(
			termInstanceId: $instanceId,
			rationale: 'Termijn afgesloten bij herbinding naar een ander zaaktype: ' . $reason,
		);

		$this->replay(successor: $successor, carried: $carried);

		return true;
	}//end rearmOne()

	/**
	 * Re-record the old term's extensions and suspensions on its successor.
	 *
	 * @param array<string, mixed>             $successor The new instance.
	 * @param array<int, array<string, mixed>> $carried   The events to carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function replay(array $successor, array $carried): void {
		$successorId = (string)($successor['id'] ?? '');
		if ($successorId === '' || $carried === []) {
			return;
		}

		$days = 0;
		$extensions = 0;
		foreach ($carried as $event) {
			$impact = (int)($event['daysImpact'] ?? 0);
			$days += $impact;
			if ((string)($event['type'] ?? '') === 'verdaging') {
				$extensions++;
			}

			$this->recordEvent(
				termInstanceId: $successorId,
				type: (string)($event['type'] ?? 'verdaging'),
				basis: (string)($event['basis'] ?? 'AWB 4:14'),
				rationale: (string)($event['rationale'] ?? ''),
				daysImpact: $impact,
				moment: $this->momentOf(event: $event),
				actor: (string)($event['actor'] ?? 'system'),
			);
		}

		if ($days === 0 && $extensions === 0) {
			return;
		}

		$calculated = (string)($successor['endDateCalculated'] ?? '');
		$current = $calculated;
		if ($calculated !== '' && $days !== 0) {
			$current = (new DateTimeImmutable($calculated))
				->modify((($days >= 0) ? '+' : '-') . abs($days) . ' days')
				->format('Y-m-d');
		}

		$this->updateTermijnInstance(
			termInstanceId: $successorId,
			patch: ['endDateCurrent' => $current, 'countExtensions' => $extensions]
		);
	}//end replay()

	/**
	 * The events of one instance that change how long it runs.
	 *
	 * `start` is excluded because the successor's own start event already
	 * carries the target definition's duration, and adding the old one would
	 * count a duration twice. `voltooi` is excluded because it ends a term
	 * rather than lengthening it.
	 *
	 * @param string $termInstanceId The instance.
	 *
	 * @return array<int, array<string, mixed>> The events, oldest first.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function carriedEvents(string $termInstanceId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_gebeurtenis_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['deadlineInstance' => $termInstanceId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermijnService.carriedEvents lookup failed, so no extension was carried forward',
				['instance' => $termInstanceId, 'error' => $e->getMessage()]
			);

			return [];
		}

		$carried = [];
		foreach ($rows as $row) {
			if (in_array((string)($row['type'] ?? ''), ['start', 'voltooi'], true) === false) {
				$carried[] = $row;
			}
		}

		usort(
			$carried,
			static fn (array $a, array $b): int
				=> strcmp((string)($a['moment'] ?? ''), (string)($b['moment'] ?? ''))
		);

		return $carried;
	}//end carriedEvents()

	/**
	 * The day a running term started, as a date.
	 *
	 * @param array<string, mixed> $instance The instance.
	 *
	 * @return DateTimeImmutable|null The start, or null when it carries none.
	 */
	private function startOf(array $instance): ?DateTimeImmutable {
		// THE ONE DATE PATH. `new DateTimeImmutable($raw)` here read the
		// PROCESS zone, so the same stored string became a different day on
		// two servers, and the swallowing catch meant nothing said so. The
		// normaliser resolves the administered zone and answers null for a
		// value it cannot read, which is the same contract without the second
		// rule.
		return $this->dates?->tryParse($instance['startDate'] ?? null);
	}//end startOf()

	/**
	 * The moment an event was recorded, as a date.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return DateTimeImmutable|null The moment, or null for now.
	 */
	private function momentOf(array $event): ?DateTimeImmutable {
		// Same rule as {@see self::startOf()}, and the reason
		// `OneDateWritePathTest` names this method by name: a private method
		// whose name reads like a date helper and whose body parses a string
		// is a second definition of what a date is.
		return $this->dates?->tryParse($event['moment'] ?? null);
	}//end momentOf()

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

		// The event row names its instance, and the instance names the case.
		// That read belongs here: this is the only class that knows how to
		// reach a term instance, and the entry writer asking for it would
		// depend on the class that depends on it. An instance that cannot be
		// read arrives as an empty array and the writer declines it, which is
		// a guard there rather than a branch this class has to carry.
		$this->termEntry?->recordEvent(
			instance: (array)$this->getTermijnInstance(termInstanceId: $termInstanceId),
			type: $type,
			basis: $basis,
			rationale: $rationale,
			moment: $moment,
		);

		return $saved;
	}//end recordEvent()

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
