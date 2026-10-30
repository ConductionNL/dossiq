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

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the handling team rules onto the live case schema.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessProjector {

	/**
	 * The schema the rules are written onto.
	 */
	public const CASE_SCHEMA_SLUG = 'case';

	/**
	 * OpenRegister's schema mapper, by the name the container knows it under.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

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
		$block = $this->rules->block(shipped: $this->shippedBlock(), caseTypes: $caseTypes);
		if ($block === $schema->getAuthorization()) {
			return false;
		}

		$schema->setAuthorization($block);
		$schemaMapper->update($schema);

		$this->logger->info(
			'Dossiq: wrote the handling team rules onto the case schema',
			['teams' => count($this->rules->teamEntries(caseTypes: $caseTypes))]
		);

		return true;
	}//end writeToSchema()
}//end class
