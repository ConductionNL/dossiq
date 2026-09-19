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
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Timeline\TermEventEntry;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-authoritative TermijnInstance lifecycle.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class TermijnService {

	/**
	 * What a case type's term definitions say.
	 *
	 * @var TermDefinitions
	 */
	private readonly TermDefinitions $definitions;

	/**
	 * Where a term instance is read and written.
	 *
	 * @var TermInstanceStore
	 */
	private readonly TermInstanceStore $store;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 * @param TermijnTimerService|null $timerService Engine timer mapping (optional while the engine rolls out).
	 * @param TermEventEntry|null $termEntry The timeline entry a term event writes.
	 * @param CaseDateNormaliser|null $dates Reads a date off a case in the one place that knows its shapes.
	 * @param TermDefinitions|null $definitions What a case type's term definitions say, and the end
	 *        date their duration implies. It took the `roll` parameter's place: counting a term in
	 *        working days is what a definition's counting mode asks for, so the calendar is reached
	 *        from there rather than from here, and no caller passed a sixth argument. Left out it is
	 *        built over the settings, the logger and the TIMER this service was given, because the
	 *        Awt roll moved into it and a default built without the timer would answer an unrolled
	 *        end date to a caller that used to get a rolled one.
	 * @param TermInstanceStore|null $store Where a term instance is read and written. Left out it is
	 *        built over the same settings and logger this service was given, because those are its
	 *        only two dependencies and a default built from them is the same store the container
	 *        wires: fifteen test builds keep working without naming a collaborator they never chose.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermEventEntry $termEntry = null,
		private readonly ?CaseDateNormaliser $dates = null,
		?TermDefinitions $definitions = null,
		?TermInstanceStore $store = null,
	) {
		$this->definitions = ($definitions ?? new TermDefinitions(
			settingsService: $settingsService,
			logger: $logger,
			roll: null,
			timer: $timerService,
		));
		$this->store = ($store ?? new TermInstanceStore(settingsService: $settingsService, logger: $logger));
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
		// COUNTED AND ROLLED IN ONE CALL. The Algemene termijnenwet roll used to
		// sit here, one line below the count, and the two always ran together.
		// They live together now, on the class that reads what the definition
		// declares, so no caller can take the count without the roll.
		$endDate = $this->definitions
			->endDateFor(start: $startDate, days: $durationDays, definitie: $definitie)
			->format('Y-m-d');

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

		$saved = $this->store->save(schemaConfigKey: 'termijn_instance_schema', object: $instance);
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
		return $this->store->read(termInstanceId: $termInstanceId);
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
		return $this->store->latestForCase(caseId: $caseId);
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
		return $this->store->allForCase(caseId: $caseId);
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
		$saved = $this->store->save(schemaConfigKey: 'termijn_instance_schema', object: $instance);

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
		return $this->store->save(schemaConfigKey: 'termijn_instance_schema', object: $merged);
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
		return $this->definitions->activeFor(caseType: $caseType);
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
		return $this->definitions->allActiveFor(caseType: $caseType);
	}//end definitionsFor()

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
			$why = trim($rationale);
			if ($why === '') {
				$why = 'Termijn voltooid door beschikking';
			}

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
				rationale: $why,
				daysImpact: 0,
				moment: $voltooiDatum,
				documentLink: $documentLink,
			);

			// Completion cancels every open timer of the instance, in the
			// same operation that made the term terminal (REQ-TOT-001).
			$this->timerService?->cancelForInstance(
				instanceId: $termInstanceId,
				reason: $why
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

		$saved = $this->store->save(schemaConfigKey: 'termijn_gebeurtenis_schema', object: $event);

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

}//end class
