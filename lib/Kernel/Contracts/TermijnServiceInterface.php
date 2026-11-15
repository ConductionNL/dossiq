<?php

/**
 * Contract of TermijnService
 *
 * The public surface of TermijnService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Exception\RefusedException;
use RuntimeException;

/**
 * Contract of TermijnService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermijnServiceInterface {

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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
	 */
	public function createTermijnInstance(
		string $caseId,
		string $caseType,
		?DateTimeImmutable $startDate = null,
		?array $resolution = null,
	): array;

	/**
	 * Get TermijnInstance by id.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnInstance(string $termInstanceId): ?array;

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
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function instancesForCase(string $caseId): array;

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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
	 */
	public function markTermijnCompleted(
		string $termInstanceId,
		?DateTimeImmutable $voltooiDatum = null,
		string $documentLink = '',
		string $rationale = '',
	): ?array;

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
	 * @param array<string, string> $notice Who the event is told to and what it tells them:
	 *        `notify` (a Nextcloud user id), `caseTitle` and `endDate`. Only those three
	 *        keys are kept, and only when not empty. A notification rule on the event
	 *        schema fires on a row that carries `notify`, so an event without a notice
	 *        tells nobody, exactly as before.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
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
		array $notice = [],
	): ?array;

	/**
	 * Update a TermijnInstance (partial; merged on top of existing).
	 *
	 * @param string $termInstanceId Instance id.
	 * @param array<string, mixed> $patch Partial patch.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
	 */
	public function updateTermijnInstance(string $termInstanceId, array $patch): ?array;
}//end interface
