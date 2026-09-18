<?php

/**
 * Carry the gezinsplan's string arrays into goals and interventions.
 *
 * 🔴 EVERY ONE OF THOSE STRINGS IS TEXT SOMEBODY WROTE ABOUT A REAL HOUSEHOLD,
 * so this migration carries them and invents nothing. `goals` and
 * `deploymentTrajectories` are plain arrays of text, and the new shapes ask for
 * a target date, a provider and what would count as met, which the old shape
 * never held. Filling those in with a guess would put a commitment nobody made
 * into a plan a family is worked to. So each record keeps its exact text, its
 * unmapped fields stay empty and it carries `migratedFrom`, which is how a
 * reader knows why the provider and the target date are blank (D-4, REQ-CPN-02).
 *
 * 🔑 THE `metWhen` OF A MIGRATED GOAL IS THE TEXT ITSELF. `casePlanGoal`
 * requires it, because a goal nobody can check is a goal no review can close,
 * and the old shape cannot supply one. Repeating the text is the one option
 * that neither drops the goal nor makes something up: the goal reads exactly as
 * it always did, the migration mark says it came from the old shape, and the
 * first review is where somebody writes down what would actually count.
 *
 * 🔑 IT IS IDEMPOTENT ON THE PLAN, NOT ON THE STRING. A plan that already has
 * migrated records is skipped whole. Matching per string would re-import a goal
 * whose text a consulent has since corrected, because the corrected text no
 * longer matches, and the household would end up with both.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turn each plan string into a goal or an intervention, once.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class MigrateCasePlanStrings implements IRepairStep {

	use RunsUnderSystemIdentity;

	/**
	 * The mark a record carries when it came from `gezinsplan.goals`.
	 *
	 * @var string
	 */
	public const FROM_GOALS = 'gezinsplan.goals';

	/**
	 * The mark a record carries when it came from `deploymentTrajectories`.
	 *
	 * @var string
	 */
	public const FROM_TRAJECTORIES = 'gezinsplan.deploymentTrajectories';

	/**
	 * The provider a migrated intervention carries.
	 *
	 * 🔴 EMPTY, AND THE INTERVENTION IS WRITTEN ANYWAY. `intervention.provider`
	 * is required and {@see CasePlanInterventions::save()} refuses a bare name,
	 * so this migration writes THROUGH THE STORE rather than through that
	 * service: the guard is right for a person filling in a form and wrong for
	 * text that predates the field. Dropping the trajectory instead would lose
	 * the one thing the old shape did record.
	 *
	 * @var string
	 */
	public const NO_PROVIDER = '';

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store           The one reader and writer of these schemas.
	 * @param SettingsService    $settingsService The object service the elevation runs on.
	 * @param LoggerInterface    $logger          The logger.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function getName(): string {
		return 'Carry each family plan\'s goal and trajectory text into goals and interventions';
	}//end getName()

	/**
	 * Migrate every plan that has not been migrated yet.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-the-existing-plan-text-is-migrated-not-discarded-req-cpn-02
	 */
	public function run(IOutput $output): void {
		// UNDER A SYSTEM IDENTITY, and the READ is inside it too. An upgrade
		// has no session, so OpenRegister resolves the actor as 'Anonymous' and
		// refuses every create and update; it also refuses the READ on any
		// schema with no explicit `public` grant, and `gezinsplan` has none. So
		// an unelevated run does not half-migrate, it reads nothing, reports
		// "migrated 0" and looks like an instance that had no plans.
		$this->withSystemIdentity(
			objectService: $this->settingsService->getObjectService(),
			work: function () use ($output): void {
				$this->migrate(output: $output);
			}
		);
	}//end run()

	/**
	 * The migration itself, once an identity is in place.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	private function migrate(IOutput $output): void {
		try {
			$plans = $this->store->rows(schema: 'gezinsplan');
		} catch (Throwable $e) {
			// A repair step that cannot read is not a repair step that found
			// nothing. It says so and leaves the data alone.
			$output->warning(
				'Dossiq: the family plans could not be read, so no plan text was migrated: ' . $e->getMessage()
			);

			return;
		}

		$migrated = 0;
		foreach ($plans as $plan) {
			$migrated += $this->migrateOne(plan: $plan, output: $output);
		}

		$output->info('Dossiq: migrated ' . $migrated . ' family plan records into goals and interventions');
	}//end migrate()

	/**
	 * Migrate one plan, or skip it when it has been done.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param IOutput              $output Output sink.
	 *
	 * @return integer How many records were written.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-the-existing-plan-text-is-migrated-not-discarded-req-cpn-02
	 */
	private function migrateOne(array $plan, IOutput $output): int {
		$planId = trim((string)($plan['id'] ?? ($plan['uuid'] ?? '')));
		if ($planId === '' || $this->alreadyMigrated(planId: $planId) === true) {
			return 0;
		}

		$written = 0;

		foreach ($this->textsOf(plan: $plan, key: 'goals') as $text) {
			$this->store->write(
				schema: CasePlanGoals::SCHEMA,
				object: [
					'plan' => $planId,
					'title' => $text,
					// The text stands in for itself: see the class docblock.
					'metWhen' => $text,
					'state' => 'open',
					'migratedFrom' => self::FROM_GOALS,
				]
			);
			$written++;
		}

		foreach ($this->textsOf(plan: $plan, key: 'deploymentTrajectories') as $text) {
			$this->store->write(
				schema: CasePlanInterventions::SCHEMA,
				object: [
					'plan' => $planId,
					'title' => $text,
					'provider' => self::NO_PROVIDER,
					'state' => 'planned',
					'migratedFrom' => self::FROM_TRAJECTORIES,
				]
			);
			$written++;
		}

		if ($written > 0) {
			$this->logger->info(
				'MigrateCasePlanStrings: a family plan\'s text became goals and interventions',
				['plan' => $planId, 'records' => $written]
			);
			$output->info('Dossiq: plan ' . $planId . ' carried ' . $written . ' records across');
		}

		return $written;
	}//end migrateOne()

	/**
	 * Whether this plan already has records from the old shape.
	 *
	 * @param string $planId The plan.
	 *
	 * @return boolean True when it has been migrated.
	 */
	private function alreadyMigrated(string $planId): bool {
		foreach ([CasePlanGoals::SCHEMA, CasePlanInterventions::SCHEMA] as $schema) {
			foreach ($this->store->rows(schema: $schema, filters: ['plan' => $planId]) as $row) {
				if (trim((string)($row['migratedFrom'] ?? '')) !== '') {
					return true;
				}
			}
		}

		return false;
	}//end alreadyMigrated()

	/**
	 * The non-empty strings a plan holds under one key.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param string               $key  The property.
	 *
	 * @return array<int, string> The texts, in the order they were written.
	 */
	private function textsOf(array $plan, string $key): array {
		$raw = ($plan[$key] ?? []);
		if (is_string($raw) === true && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		$texts = [];
		foreach ($raw as $value) {
			$text = trim((string)$value);
			if ($text !== '') {
				$texts[] = $text;
			}
		}

		return $texts;
	}//end textsOf()
}//end class
