<?php

/**
 * Publish who handles which case type onto the case schema's access rules.
 *
 * Ruben decided on 2026-10-11 that the handling teams of a case type read and
 * update the cases of that type (REQ-ACC-10 as amended by
 * `case-access-by-handling-team`). The teams live on the case type rows
 * (`handling.defaultGroup` and `handling.teams`, read by
 * {@see CaseTypeHandling::teams()}), and OpenRegister reads its rules from the
 * schema, so this class carries the one onto the other. It writes a rule; it
 * never decides whether somebody may read. That stays OpenRegister's
 * (REQ-ACC-01).
 *
 * 🔴 IT RUNS AFTER EVERY REGISTER IMPORT. The import writes the case schema's
 * `authorization` from the register JSON, which carries the shipped block and
 * not the team rules. Nothing errors when they are gone: every handling team
 * simply stops seeing its cases. That fails closed, which is the right
 * direction, and it is still a whole department locked out until somebody saves
 * a case type. So {@see InitializeSettings} calls {@see reapply()} after the
 * import, as it does for the per-role field rules.
 *
 * 🔴 IT READS EVERY CASE TYPE, WHOEVER SAVED ONE. A case type save by an
 * account that cannot read every case type would otherwise publish the teams
 * of only the rows it can see, and take the rest away.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseTeamAccessProjectorInterface;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the handling team rules onto the live case schema.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessProjector implements CaseTeamAccessProjectorInterface {

	/**
	 * The schema the rules are written onto.
	 */
	public const CASE_SCHEMA_SLUG = 'case';

	/**
	 * OpenRegister's schema mapper, by the name the container knows it under.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * OpenRegister's record of what an app shipped for a schema.
	 */
	private const BASELINE_STORE = 'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedBaselineStore';

	/**
	 * The app config key that lists every team the projector has written a rule for.
	 */
	public const MANAGED_TEAMS_KEY = 'case_team_access_managed_teams';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore          $store     Reads every case type.
	 * @param CaseTypeHandling       $handling  The one reader of a case type's handling teams.
	 * @param CaseTeamAccessRules    $rules     Turns the case types into the block.
	 * @param SchemaSlugResolver     $slugs     Resolves the case schema inside our own register.
	 * @param RegisterFragmentMerger $fragments Folds the register fragments onto the base JSON.
	 * @param ContainerInterface     $container The DI container, for OpenRegister's SchemaMapper.
	 * @param LoggerInterface        $logger    The logger.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly CaseTypeHandling $handling,
		private readonly CaseTeamAccessRules $rules,
		private readonly SchemaSlugResolver $slugs,
		private readonly RegisterFragmentMerger $fragments,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the case schema's block from the case types and write it when it changed.
	 *
	 * A failure is logged and answered false: a case type save must not fail
	 * because the schema could not be written, and the next save or import
	 * writes the rules again.
	 *
	 * @return bool True when the schema was rewritten.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function reapply(): bool {
		// Asked, not caught: an instance without OpenRegister is an ordinary
		// state, and a swallowed exception would read the same as a failed write.
		if ($this->container->has(self::SCHEMA_MAPPER) === false) {
			$this->logger->info('Dossiq: no OpenRegister SchemaMapper, so the handling team rules were not written');
			return false;
		}

		try {
			return $this->writeToSchema();
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not write the handling team rules onto the case schema',
				['exception' => $e->getMessage()]
			);
			return false;
		}
	}//end reapply()

	/**
	 * Every case type with its handling teams.
	 *
	 * @return array<int, array{id: string, teams: array<int, string>}> The case types.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function caseTypeTeams(): array {
		$caseTypes = [];
		foreach ($this->store->everyCaseType(scope: ['_rbac' => false, '_multitenancy' => false]) as $row) {
			$caseTypes[] = [
				'id' => $this->store->rowId(row: $row),
				'teams' => $this->handling->teams(caseType: $row),
			];
		}

		return $caseTypes;
	}//end caseTypeTeams()

	/**
	 * The authorization the register JSON declares on the case schema.
	 *
	 * Read from the fragment-merged JSON, never from the live schema. Taking the
	 * live block as the base would keep last run's team rules and add this
	 * run's beside them, so a team taken off a case type would keep its cases.
	 *
	 * @return array<string, mixed> The shipped block.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function shippedBlock(): array {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		$configData = json_decode((string)file_get_contents($configPath), true);
		if (is_array($configData) === false) {
			return [];
		}

		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);

		$block = ($configData['components']['schemas'][self::CASE_SCHEMA_SLUG]['authorization'] ?? []);
		if (is_array($block) === false) {
			return [];
		}

		return $block;
	}//end shippedBlock()

	/**
	 * Write the block, letting failure throw.
	 *
	 * @return bool True when the schema was written.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	private function writeToSchema(): bool {
		$schemaMapper = $this->container->get(self::SCHEMA_MAPPER);
		if (is_object($schemaMapper) === false) {
			return false;
		}

		$schema = $this->slugs->resolve(schemaMapper: $schemaMapper, slug: self::CASE_SCHEMA_SLUG);
		if ($schema === null) {
			return false;
		}

		$caseTypes = $this->caseTypeTeams();
		$desired = $this->rules->block(shipped: $this->shippedBlock(), caseTypes: $caseTypes);
		$live = ($schema->getAuthorization() ?? []);
		$managed = $this->managedTeams(current: $caseTypes);
		$merge = (new CaseAuthorizationMerge())->merge(
			desired: $desired,
			live: $live,
			baseline: $this->recordedAuthorization(),
			managed: $managed,
		);
		$block = $merge['block'];
		$this->reportKeptLocal(conflicts: $merge['conflicts']);
		$this->reportRestored(restored: $merge['restored']);
		$changed = ($block !== $live);
		if ($changed === true) {
			$schema->setAuthorization($block);
			$schemaMapper->update($schema);
			$this->logger->info(
				'Dossiq: wrote the handling team rules onto the case schema',
				['teams' => count($this->rules->teamEntries(caseTypes: $caseTypes))]
			);
		}

		$this->alignShippedBaseline(block: $desired);
		$this->rememberManagedTeams(teams: $managed);

		return $changed;
	}//end writeToSchema()

	/**
	 * Every team the projector manages: the teams the case types name now, and every team it wrote a rule for before.
	 *
	 * A team rule for one of these is the projector's to rewrite or remove,
	 * whatever the baseline says, so a team that left every case type loses
	 * its rule even when the baseline lags.
	 *
	 * @param array<int, array{id: string, teams: array<int, string>}> $current Every case type, with its handling teams.
	 *
	 * @return array<int, string> The teams, sorted.
	 */
	private function managedTeams(array $current): array {
		$teams = array_column($this->rules->teamEntries(caseTypes: $current), 'group');
		$stored = json_decode($this->appConfig()->getValueString(app: AppIdentity::APP_ID, key: self::MANAGED_TEAMS_KEY, default: '[]'), true);
		if (is_array($stored) === true) {
			$teams = array_merge($teams, array_filter($stored, 'is_string'));
		}

		$teams = array_values(array_unique(array_map('strval', $teams)));
		sort($teams, SORT_STRING);

		return $teams;
	}//end managedTeams()

	/**
	 * Remember the managed teams, so a team that leaves every case type is still known as the projector's.
	 *
	 * @param array<int, string> $teams The managed teams.
	 *
	 * @return void
	 */
	private function rememberManagedTeams(array $teams): void {
		$this->appConfig()->setValueString(app: AppIdentity::APP_ID, key: self::MANAGED_TEAMS_KEY, value: (string)json_encode($teams));
	}//end rememberManagedTeams()

	/**
	 * The app config, from the container the projector already holds.
	 *
	 * @return IAppConfig The app config.
	 */
	private function appConfig(): IAppConfig {
		return $this->container->get(IAppConfig::class);
	}//end appConfig()

	/**
	 * The authorization the projector recorded last, as the case schema's shipped baseline.
	 *
	 * @return array<string, mixed>|null The block, or null when none was recorded or it cannot be read.
	 */
	private function recordedAuthorization(): ?array {
		if ($this->container->has(self::BASELINE_STORE) === false) {
			return null;
		}

		// An unreadable baseline reads as none (null, never an empty baseline).
		// The merge then keeps every rule that is not a managed team rule, does
		// not bring back a shipped rule missing from the live block, keeps a map
		// that differs, and reports each; managed team rules follow the case
		// types. So it widens nothing an administrator narrowed.
		try {
			$baselines = $this->container->get(self::BASELINE_STORE);
			$baseline = $baselines->read(subject: $baselines->schemaSubject(slug: self::CASE_SCHEMA_SLUG));
		} catch (Throwable) {
			$baseline = null;
		}

		$block = ($baseline['definition']['authorization'] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		return $block;
	}//end recordedAuthorization()

	/**
	 * Log the projector rules an administrator changed, which the projector left as they are.
	 *
	 * @param array<int, string> $conflicts The `action` or `action[rule]` paths kept local.
	 *
	 * @return void
	 */
	private function reportKeptLocal(array $conflicts): void {
		if ($conflicts === []) {
			return;
		}

		$this->logger->warning(
			'Dossiq: the case schema kept local changes to handling team rules; the projector did not overwrite them',
			['keptLocal' => $conflicts]
		);
	}//end reportKeptLocal()

	/**
	 * Log the handling team rules an administrator edited or added that the projector restored to what the case types grant.
	 *
	 * Ruben decided on 3 November 2026 that the case types win for a team
	 * rule, and that the overwrite is reported, so the edit is not lost in silence.
	 *
	 * @param array<int, string> $restored The `action[rule]` paths restored.
	 *
	 * @return void
	 */
	private function reportRestored(array $restored): void {
		if ($restored === []) {
			return;
		}

		$this->logger->warning(
			'Dossiq: the case schema had local changes to handling team rules, which were replaced. '
			. 'These rules follow the case type\'s teams; change the case type instead.',
			['restored' => $restored]
		);
	}//end reportRestored()

	/**
	 * Record the projector's own block as the case schema's shipped authorization.
	 *
	 * OpenRegister keeps what an app shipped beside what the instance runs, and
	 * on an upgrade it keeps a part the instance changed instead of the newly
	 * shipped one. Without this, the block written above reads as a local edit,
	 * so a later shipped change to the case schema's authorization is kept out
	 * as a conflict. With it, the upgrade applies the shipped change and the
	 * next reapply puts the team rules back on top of it.
	 *
	 * Only an existing baseline is aligned: without one the import has nothing
	 * to compare against and writes the shipped block anyway. A failure is
	 * logged and does not undo the schema write.
	 *
	 * @param array<string, mixed> $block The block the projector derives: shipped plus team rules, without local rules.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	private function alignShippedBaseline(array $block): void {
		if ($this->container->has(self::BASELINE_STORE) === false) {
			return;
		}

		try {
			$baselines = $this->container->get(self::BASELINE_STORE);
			$subject = $baselines->schemaSubject(slug: self::CASE_SCHEMA_SLUG);
			$baseline = $baselines->read(subject: $subject);
			if (is_array($baseline['definition'] ?? null) === false || ($baseline['definition']['authorization'] ?? null) === $block) {
				return;
			}

			$definition = $baseline['definition'];
			$definition['authorization'] = $block;
			$recorded = $baselines->record(
				subject: $subject,
				shipped: new ShippedDefinition(
					definition: $definition,
					app: $baseline['app'],
					appVersion: $baseline['appVersion'],
				),
			);
		} catch (Throwable) {
			$recorded = false;
		}

		if ($recorded === false) {
			$this->logger->warning('Dossiq: the case schema\'s shipped baseline was not aligned with the handling team rules');
		}
	}//end alignShippedBaseline()
}//end class
