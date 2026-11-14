<?php

/**
 * Dossiq RenameDutchDeadlineColumns Repair Step
 *
 * Moves stored data from the Dutch column names to the English ones the
 * dossiq register declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * Renaming `onderwerp` to `subject` in the register therefore leaves the data
 * in `onderwerp` while every read looks at `subject` and finds null. No error,
 * no data loss, and invisible to the suites, which assert against fixtures
 * rather than migrated rows.
 *
 * WHY IT MATCHES TWO REGISTERS, NOT ONE. This install carries BOTH `dossiq`
 * (1051 rows) and `dossiq-default` (107 rows). The sibling step in decidesk
 * resolves a single exact slug; doing that here would silently skip 107 rows
 * and report success. The register set is therefore resolved by slug prefix and
 * every match is migrated.
 *
 * WHAT IS EXEMPT. `zaaktype` is a ZGW term: it is the field name in the
 * statutory Zaakgericht Werken wire format that this app both consumes and
 * emits, so it is exempt under the fleet vocabulary rule and is deliberately
 * absent from the map below — even though it is the second most widespread
 * Dutch column here (14 shard tables).
 *
 * COLLISIONS ARE REFUSED, NOT MERGED. `omschrijving` and `beschrijving` both
 * mean `description`. Measured: they do not co-occur in any shard table on this
 * install. A later fragment could introduce a pair, and a silent merge would
 * destroy one of two values, so the step detects two sources targeting one
 * destination in a table, migrates NEITHER, and logs.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so the step is
 *     reversible and a re-run is a no-op;
 *   - nothing is deleted, and soft-deleted rows are migrated too — the
 *     back-fill does not filter on `_deleted`, because a restored row must not
 *     come back with a null subject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\ColumnMove;
use OCA\Dossiq\Repair\Support\MovesShardColumns;
use OCA\Dossiq\Repair\Support\ReadsRegisterShards;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename dossiq's Dutch deadline columns to their English equivalents.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class RenameDutchDeadlineColumns implements IRepairStep {
	use ReadsRegisterShards;
	use MovesShardColumns;

	/**
	 * The name every warning starts with.
	 */
	private const LOG_NAME = 'RenameDutchDeadlineColumns';

	/**
	 * Slug prefix of the registers in scope.
	 *
	 * Matches `dossiq` and `dossiq-default`; both hold live rows.
	 *
	 * @var string
	 */
	// The OpenRegister register SLUG. It MOVES with the app id: the
	// `MigrateRegisterSlug` step renames `procest` -> `dossiq` (and
	// `dossiq-default` -> `dossiq-default`) on the existing register rows and
	// is registered ahead of this step in both info.xml blocks. Renaming a
	// register strands nothing, because objects are bound to it by NUMERIC id:
	// every shard table's `_register` column holds that id, and the tables are
	// named `oc_openregister_table_<registerId>_<schemaId>`. What the ordering
	// buys is that this step still resolves a register at all — run before
	// MigrateRegisterSlug it would match none and migrate nothing, silently.
	private const REGISTER_SLUG_PREFIX = 'dossiq';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `endDateActual` as
	 * `end_date_actual`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * `zaaktype` is deliberately ABSENT — see the class docblock.
	 *
	 * `toelichting` maps to `notes`, NOT `description`: it co-occurs with
	 * `omschrijving` elsewhere in the fleet, so they are distinct concepts.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'name' => 'name',
		'onderwerp' => 'subject',
		'omschrijving' => 'description',
		'beschrijving' => 'description',
		'notes' => 'notes',
		'rationale' => 'rationale',
		'department' => 'department',
		'aantal_verlengingen' => 'extension_count',
		'einddatum_actueel' => 'end_date_actual',
		'pauze_deadline' => 'pause_deadline',

		// Mandate-decision slug rename (61-mandaat-matrix).
		//
		// Each of these was verified to be owned by EXACTLY ONE schema before
		// being added. That check is not optional: this map is applied to every
		// shard table in the dossiq registers, so a column name shared with a
		// schema that still declares the Dutch spelling would have its data
		// migrated out from under its own declaration.
		//
		// `wettelijke_grondslag` is now safe to add: ALL SIX owning schemas
		// (dwangsomUitbetaling, mandaat, mandateDecision, subsidieBeschikking,
		// termijnDefinitie, terugvordering) are renamed in the same change, so
		// no declaration is left pointing at the Dutch column. It was held back
		// from the mandate slice for exactly this reason — a register-scoped
		// migration cannot rename a column for one owner and not the rest.
		'wettelijke_grondslag' => 'legal_basis',
		'besluit_nummer' => 'decision_number',
		'besluit_naam' => 'decision_name',
		'in_werkingtreding' => 'effective_from',
		'verval_datum' => 'expiry_date',
		'vorig_besluit' => 'previous_decision',
		'mandaterings_besluit' => 'mandate_decision',

		// Mandaat -> mandate. All four owning schemas (mandaat, mandaatGebruik,
		// mandaatEscalatie, mandaatRegeling) are renamed in this same change and
		// all four live in the `dossiq` register, so this map's scope covers
		// every owner — no declaration is left pointing at a Dutch column.
		//
		// Ownership was checked per property first: each of these is declared by
		// exactly ONE schema. That check is what keeps a shared column out of a
		// register-scoped map.
		//
		// NOT here: `mandaatregeling_id` and the `mandaat_niveau` inside
		// `mandateGranted`. Those are NESTED under an object property, so they
		// live in the JSON of that column rather than as columns of their own.
		// A column rename cannot reach them; they need a JSON-rewrite migration,
		// which is a different mechanism and a separate change.
		'mandaat_nummer' => 'mandate_number',
		'mandaat_id' => 'mandate_id',
		'mandaat_versie_id' => 'mandate_version_id',
		'target_mandaat_id' => 'target_mandate_id',
		'mandaat_groepen' => 'mandate_groups',
		'ondermandaat_toegestaan' => 'sub_mandate_allowed',
		'mandaat_niveau' => 'mandate_level',
		'mandaat_gegeven' => 'mandate_granted',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function getName(): string {
		return 'Move dossiq data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every dossiq shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchDeadlineColumns: no dossiq shard tables on this install; nothing to do.');
			return;
		}

		$tally = $this->moveColumnsOfAll(tables: $tables);
		$output->info(
			'RenameDutchDeadlineColumns: ' . $tally->renamed . ' column(s) renamed, '
			. $tally->copied . ' back-filled, ' . $tally->refused . ' refused for ambiguity, across '
			. count($tables) . ' dossiq shard table(s).'
		);

	}//end run()

	/**
	 * Whether two Dutch columns in this table both target one English name.
	 *
	 * @param string     $table Table name.
	 * @param ColumnMove $move  The move, with the table's column names.
	 *
	 * @return bool True when the rename is ambiguous and must be skipped.
	 */
	protected function refusesMove(string $table, ColumnMove $move): bool {
		return $this->hasCollision(table: $table, columns: $move->columns, target: $move->new);
	}//end refusesMove()

	/**
	 * Whether two Dutch columns in this table both target one English name.
	 *
	 * @param string $table Table name.
	 * @param array<int, string> $columns Its column names.
	 * @param string $target The English destination name.
	 *
	 * @return bool True when the rename is ambiguous and must be skipped.
	 */
	private function hasCollision(string $table, array $columns, string $target): bool {
		$sources = [];
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources[] = $old;
			}
		}

		if (count($sources) < 2) {
			return false;
		}

		$this->logger->warning(
			'RenameDutchDeadlineColumns: refusing an ambiguous rename; two source columns target one destination.',
			['table' => $table, 'sources' => $sources, 'target' => $target]
		);

		return true;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIdsOrNone(warning: 'could not resolve the dossiq registers; skipping.');
		if ($ids === []) {
			return [];
		}

		$markers = $this->shardMarkers(ids: $ids);
		$tables = [];
		foreach ($this->openRegisterTableNames() as $name) {
			if ($this->isShardOf(table: $name, markers: $markers) === true) {
				$tables[] = $name;
			}
		}

		return array_values(array_unique($tables));
	}//end shardTables()

	/**
	 * Whether a table name is a shard of one of the given registers.
	 *
	 * @param string $table Table name from information_schema.
	 * @param array<int, string> $markers `openregister_table_<registerId>_` prefixes.
	 *
	 * @return bool
	 */
	private function isShardOf(string $table, array $markers): bool {
		if ($table === '') {
			return false;
		}

		foreach ($markers as $marker) {
			$offset = strpos($table, $marker);
			if ($offset === false) {
				continue;
			}

			// Everything after the marker must be the numeric schema id, so a
			// derived table (…_17_85_backup) or a non-shard (…_17_audit) is not
			// migrated. Register 17 vs 170 is NOT what this guards: the marker
			// already ends in '_', so `openregister_table_17_` is simply not a
			// substring of `openregister_table_170_85`. An earlier revision of
			// this comment claimed otherwise; the test proved it false.
			if (ctype_digit(substr($table, ($offset + strlen($marker)))) === true) {
				return true;
			}
		}

		return false;
	}//end isShardOf()

}//end class
