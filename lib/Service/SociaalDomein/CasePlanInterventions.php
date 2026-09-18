<?php

/**
 * The interventions of a gezinsplan, and which of them are overdue.
 *
 * 🔴 THE INTERVENTION IS THE UNIT, NOT THE GOAL (D-1). A goal with no
 * intervention under it is a wish, and an intervention that names no goal is
 * activity. Modelling the intervention and letting it name the goal it serves
 * keeps both, and it is what lets three interventions serve one goal, which is
 * what a real plan looks like.
 *
 * 🔑 OVERDUE IS COMPUTED, NOT STORED. A stored flag is a flag somebody has to
 * remember to clear, and a plan whose overdue marks are a week stale is worse
 * than one with none: it reads as checked. So the comparison is made at read
 * time against the day the reader is on, and a completed intervention is never
 * overdue however far its target date has passed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
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
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Save and read the interventions of one plan.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class CasePlanInterventions {

	/**
	 * The schema slug interventions are stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'intervention';

	/**
	 * The states an intervention can be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['planned', 'running', 'complete', 'stopped'];

	/**
	 * The states in which a passed target date still means something.
	 *
	 * A stopped intervention is not overdue either: somebody decided it stops,
	 * and reporting it as late would put a decision in the overdue list.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_STATES = ['planned', 'running'];

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store    The one reader and writer of these schemas.
	 * @param InterventionProvider $provider Providers, as parties.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
		private readonly InterventionProvider $provider,
	) {
	}//end __construct()

	/**
	 * The interventions of one plan, each saying whether it is overdue.
	 *
	 * @param string $planId The gezinsplan uuid.
	 * @param string $today  The day to compare against, `Y-m-d`, or '' for today.
	 *
	 * @return array<int, array<string, mixed>> The interventions, with `overdue` on each.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function of(string $planId, string $today = ''): array {
		if (trim($planId) === '') {
			return [];
		}

		if ($today === '') {
			$today = (new DateTimeImmutable())->format('Y-m-d');
		}


		$rows = [];
		foreach ($this->store->rows(schema: self::SCHEMA, filters: ['plan' => $planId]) as $row) {
			$row['overdue'] = $this->isOverdue(intervention: $row, today: $today);
			$row['providerParty'] = $this->provider->resolve(
				reference: (string)($row['provider'] ?? ''),
				objectId: $planId
			);
			$rows[] = $row;
		}

		return $rows;
	}//end of()

	/**
	 * Whether this intervention's target date has passed with it still open.
	 *
	 * @param array<string, mixed> $intervention The intervention.
	 * @param string               $today        The day, `Y-m-d`.
	 *
	 * @return boolean True when it is overdue.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function isOverdue(array $intervention, string $today): bool {
		$target = trim((string)($intervention['targetDate'] ?? ''));
		if ($target === '') {
			return false;
		}

		$state = trim((string)($intervention['state'] ?? 'planned'));
		if (in_array($state, self::OPEN_STATES, true) === false) {
			return false;
		}

		// String comparison, and it is sound because both sides are `Y-m-d`:
		// the schema declares `format: date` and the caller's day is formatted
		// here. A date-time on either side would break it, which is why
		// neither side is ever handed one.
		return ($target < $today);
	}//end isOverdue()

	/**
	 * Save an intervention, refusing a provider that is a typed name.
	 *
	 * @param array<string, mixed> $intervention The intervention.
	 *
	 * @return array<string, mixed> The stored intervention.
	 *
	 * @throws RefusedException When the intervention is incomplete, or the write fails.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function save(array $intervention): array {
		if (trim((string)($intervention['plan'] ?? '')) === '') {
			throw new RefusedException(
				rule: 'intervention-needs-a-plan',
				sentence: 'Say which family plan this intervention belongs to.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (trim((string)($intervention['title'] ?? '')) === '') {
			throw new RefusedException(
				rule: 'intervention-needs-a-title',
				sentence: 'Say what this intervention is.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$this->provider->assertReference(value: (string)($intervention['provider'] ?? ''));

		$state = trim((string)($intervention['state'] ?? ''));
		if (in_array($state, self::STATES, true) === false) {
			$state = 'planned';
		}

		$intervention['state'] = $state;
		$intervention['migratedFrom'] = (string)($intervention['migratedFrom'] ?? '');

		return $this->store->write(schema: self::SCHEMA, object: $intervention);
	}//end save()
}//end class
