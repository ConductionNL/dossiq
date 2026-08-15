<?php

/**
 * Renames this app's Dutch SCHEMA SLUGS in place, before the register import.
 *
 * OpenRegister's ImportHandler matches an incoming schema to an existing one by
 * SLUG (`SchemaMapper::findBySlugInIds()`). So changing a slug in the register
 * JSON does not rename anything: the import finds no match, CREATES a second
 * schema, and every object already stored keeps pointing at the old one. The
 * data is not lost — it is stranded behind a schema nothing reads any more, and
 * the app looks like it simply has no records.
 *
 * This step renames the slug on the existing row first, so the import that
 * follows recognises the schema and updates it instead of forking it. The shard
 * table is named for the register and schema IDs, which this step never
 * touches, so the rows move with the schema untouched.
 *
 * ORDERING: this MUST run before `InitializeSettings` (which triggers the
 * procest register import) and before `RegisterOriRegister` (which imports the
 * ORI register). Registered first in info.xml's post-migration block.
 *
 * `bezwaar` is deliberately absent. procest declares BOTH `bezwaar` and
 * `objection`, and they are two entities rather than a duplicate — the
 * independence check resolves `bacAdviceRequest.bezwaar -> bezwaar (lifecycle
 * record) -> bezwaar.case -> objection (filed on that case)`, and SettingsService
 * carries separate `bezwaar_schema` and `objection_schema` keys. Naming the
 * lifecycle record in English is a design decision, not a translation.
 *
 * @category  Repair
 * @package   OCA\Procest\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames Dutch schema slugs on the rows the import will match against.
 */
class RenameDutchSchemaSlugs implements IRepairStep {

	/**
	 * Old slug => new slug, for schemas this app owns.
	 *
	 * Targets are read off each schema's own English title where it had one:
	 * `catalogus` was already titled "Catalog", `voorstel` "Proposal", `kanaal`
	 * "Notification Channel".
	 *
	 * The six `ori` schemas are the Open Raadsinformatie / VNG ODS-O standard's
	 * vocabulary. They are renamed because a SCHEMA is English; the standard's
	 * own spelling belongs in a MAPPING, which is configuration. procest has no
	 * ORI mapping yet — it has `LoadDefaultZgwMappings` for ZGW and nothing
	 * equivalent for ORI — so nothing consumes the standard's spelling from
	 * these schemas today.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'agendapunt' => 'agendaItem',
		'avgClassificatie' => 'gdprClassification',
		'catalogus' => 'catalog',
		'dwangsomBerekening' => 'penaltyPaymentCalculation',
		'fractie' => 'politicalGroup',
		'ingebrekestelling' => 'noticeOfDefault',
		'kanaal' => 'notificationChannel',
		'raadsdocument' => 'councilDocument',
		'raadslid' => 'councilMember',
		'stemming' => 'vote',
		'termijnDefinitie' => 'deadlineDefinition',
		'termijnInstance' => 'deadlineInstance',
		'vergadering' => 'meeting',
		'voorstel' => 'proposal',
	];

	/**
	 * Registers whose schemas are in scope.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_SLUGS = ['procest', 'ori'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                  $db        Database connection.
	 * @param LoggerInterface                $logger    Logger.
	 * @param RenameDutchSchemaSlugDecisions $decisions The pure predicates.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchSchemaSlugDecisions $decisions = new RenameDutchSchemaSlugDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Rename Dutch Procest schema slugs';
	}//end getName()

	/**
	 * Rename the slugs on this app's existing schema rows.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$schemaIds = $this->inScopeSchemaIds();
		if ($schemaIds === []) {
			$output->info('RenameDutchSchemaSlugs: no Procest registers on this install; nothing to do.');
			return;
		}

		$existing = $this->slugsOf(schemaIds: $schemaIds);
		$plan = $this->decisions->plan(map: self::SLUG_MAP, existing: $existing);

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: ' . $why . '; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new, schemaIds: $schemaIds) === true) {
				$renamed++;
			}
		}

		$output->info(
			sprintf(
				'RenameDutchSchemaSlugs: %d slug(s) renamed, %d refused.',
				$renamed,
				count($plan['refused'])
			)
		);
	}//end run()

	/**
	 * Resolve the schema ids belonging to this app's registers.
	 *
	 * @return array<int, int>
	 */
	private function inScopeSchemaIds(): array {
		$placeholders = implode(',', array_fill(0, count(self::REGISTER_SLUGS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				self::REGISTER_SLUGS
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->decisions->schemaIdsFrom(rows: $rows);
	}//end inScopeSchemaIds()

	/**
	 * Read the slugs currently held by the given schemas.
	 *
	 * @param array<int, int> $schemaIds Schema ids to read.
	 *
	 * @return array<int, string>
	 */
	private function slugsOf(array $schemaIds): array {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE id IN (' . $placeholders . ')',
				array_map(static fn (int $id): string => (string)$id, $schemaIds)
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not read schema slugs; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $row): string => (string)$row['slug'], $rows);
	}//end slugsOf()

	/**
	 * Rename one slug, scoped to this app's schemas.
	 *
	 * @param string          $old       Current slug.
	 * @param string          $new       Replacement slug.
	 * @param array<int, int> $schemaIds Schema ids in scope.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new, array $schemaIds): bool {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND id IN (' . $placeholders . ')',
				array_merge([$new, $old], array_map(static fn (int $id): string => (string)$id, $schemaIds))
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()
}//end class
