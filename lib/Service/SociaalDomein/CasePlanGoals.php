<?php

/**
 * The goals of a gezinsplan, and what would count as meeting one.
 *
 * 🔴 "DE THUISSITUATIE VERBETEREN" CANNOT BE CLOSED BY ANYBODY, AND THAT IS
 * THE DEFECT THIS FILE REFUSES. A goal with no observable outcome attached is
 * a sentence a review has to have an opinion about rather than a decision it
 * can take, and a household cannot check for themselves whether it was met.
 * `metWhen` is therefore required on save, and a goal is closed against it:
 * the closing observation is recorded beside the thing it was measured
 * against, so the two can be read together a year later.
 *
 * 🔑 THE REFUSAL IS ON SAVE AND NOT ONLY IN THE FORM. A required field in a
 * dialog is a field an API call does not have to send.
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
 * Save, read and close the goals of one plan.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class CasePlanGoals {

	/**
	 * The schema slug goals are stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'casePlanGoal';

	/**
	 * The states a goal can be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['open', 'met', 'not-met', 'withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store The one reader and writer of these schemas.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
	) {
	}//end __construct()

	/**
	 * The goals of one plan.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return array<int, array<string, mixed>> The goals.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function of(string $planId): array {
		if (trim($planId) === '') {
			return [];
		}

		return $this->store->rows(schema: self::SCHEMA, filters: ['plan' => $planId]);
	}//end of()

	/**
	 * Save a goal, refusing one that names nothing measurable.
	 *
	 * @param array<string, mixed> $goal The goal.
	 *
	 * @return array<string, mixed> The stored goal.
	 *
	 * @throws RefusedException When the goal is not evaluable, or the write fails.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function save(array $goal): array {
		$plan = trim((string)($goal['plan'] ?? ''));
		if ($plan === '') {
			throw new RefusedException(
				rule: 'goal-needs-a-plan',
				sentence: 'Say which family plan this goal belongs to.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (trim((string)($goal['title'] ?? '')) === '') {
			throw new RefusedException(
				rule: 'goal-needs-a-title',
				sentence: 'Write down the goal itself, in the family\'s own words where you can.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (trim((string)($goal['metWhen'] ?? '')) === '') {
			throw new RefusedException(
				rule: 'goal-needs-what-counts-as-met',
				sentence: 'Say what would be observably true if this goal were met. '
					. 'A goal nobody can check is a goal no review can close.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$goal['state'] = $this->stateOf(goal: $goal);
		// Not `?? ''` on the way in: a caller that omits the mark entirely and
		// a migration that sets it are different facts, and the empty string is
		// what "entered as a goal" means on this schema.
		$goal['migratedFrom'] = (string)($goal['migratedFrom'] ?? '');

		return $this->store->write(schema: self::SCHEMA, object: $goal);
	}//end save()

	/**
	 * Close a goal against the thing it said would count as met.
	 *
	 * The observation is REQUIRED, and it is the reason this is a method rather
	 * than a state write: closing a goal with nothing written beside it leaves
	 * a record that says a household reached something, with no way to know
	 * what anybody saw.
	 *
	 * @param string $goalId      The goal uuid.
	 * @param string $state       `met`, `not-met` or `withdrawn`.
	 * @param string $observation What was seen.
	 * @param string $on          The day, `Y-m-d`, or '' for today.
	 *
	 * @return array<string, mixed> The closed goal.
	 *
	 * @throws RefusedException When the goal cannot be read, the state is not a closing one, or nothing was observed.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function close(string $goalId, string $state, string $observation, string $on = ''): array {
		$goal = $this->store->read(schema: self::SCHEMA, id: $goalId);
		if ($goal === null) {
			throw new RefusedException(
				rule: 'goal-not-found',
				sentence: 'That goal could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (in_array($state, ['met', 'not-met', 'withdrawn'], true) === false) {
			throw new RefusedException(
				rule: 'goal-close-needs-a-closing-state',
				sentence: 'A goal is closed as met, not met or withdrawn.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$observation = trim($observation);
		if ($observation === '') {
			throw new RefusedException(
				rule: 'goal-close-needs-an-observation',
				sentence: 'Write what you saw, against what this goal said would count as met: '
					. '"' . trim((string)($goal['metWhen'] ?? '')) . '".',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$goal['state'] = $state;
		$goal['closedObservation'] = $observation;
		if ($on === '') {
			$on = (new DateTimeImmutable())->format('Y-m-d');
		}

		$goal['closedDate'] = $on;

		return $this->store->write(schema: self::SCHEMA, object: $goal);
	}//end close()

	/**
	 * The state a goal carries, defaulting to open.
	 *
	 * @param array<string, mixed> $goal The goal.
	 *
	 * @return string The state.
	 */
	private function stateOf(array $goal): string {
		$state = trim((string)($goal['state'] ?? ''));

		if (in_array($state, self::STATES, true) === true) {
			return $state;
		}

		return 'open';
	}//end stateOf()
}//end class
