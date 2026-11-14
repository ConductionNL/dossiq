<?php

/**
 * Dossiq RenameCollidingSchemaSlugs repair step.
 *
 * Moves this app's share of the cross-app slug collisions onto namespaced slugs IN
 * PLACE, before the register import.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so when two apps declare one slug the lookup answers with whichever
 * row it reached first.
 *
 * `supplierInvoice` is this app's case-side view of a supplier invoice: the document
 * as it arrives in a dossier. shillinq's is the accounts-payable record, more than
 * twice the size, and it owns the invoice. The two share only `invoiceDate` and
 * `dueDate` — no invoice number, nothing that identifies the document — so they are
 * renamed apart rather than folded.
 *
 * Why a repair step and why before the import. OpenRegister matches an existing schema
 * by (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and
 * creates a NEW schema when that misses. A slug rename in the shipped fragment
 * therefore does not rename anything, it CREATES a second schema and silently orphans
 * the first together with every object already written against it. The old schema keeps
 * its shard table and its rows; the app resolves the new id and reads an empty
 * collection. Nothing errors.
 *
 * Separate from {@see RenameDutchSchemaSlugs}, which is the Dutch-to-English vocabulary
 * pass. Folding this into that map would say they are the same migration, and the next
 * person reading it would have no way to tell why an English slug sits in a list of
 * Dutch translations.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://dossiq.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's colliding schema slugs in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameCollidingSchemaSlugs implements IRepairStep {
	/**
	 * Old slug => new slug, with the app it collided with.
	 *
	 * @var array<string, array{to: string, with: string}>
	 */
	private const RENAMES = [
		'supplierInvoice' => ['to' => 'caseSupplierInvoice', 'with' => 'shillinq'],
		// 🔴 THE CASE TASK IS NOT HERE ANY MORE, AND LEAVING IT WOULD HAVE UNDONE
		// THE DELETION ONCE PER UPGRADE. `task` was claimed by three apps, so this
		// step renamed dossiq's to `caseTask` before every register import. The
		// task engine is the record now and remove-casetask removed the schema
		// from both descriptors; a rename that still ran would re-create the slug
		// on the next `occ upgrade`, against a descriptor that no longer declares
		// it. A schema nothing ships and nothing reads is worse than a collision:
		// it answers 200 and returns rows no writer has touched.
	];

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'dossiq';

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
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the shillinq schema slugs that collided with another app';
	}//end getName()

	/**
	 * Rename each slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		foreach (self::RENAMES as $from => $declared) {
			$rename = new SlugRename(from: $from, to: $declared['to']);
			$rename->with = $declared['with'];
			$this->renameOne(output: $output, rename: $rename);
		}
	}//end run()

	/**
	 * Rename one slug, unless the table is unreadable, there is nothing to rename, or the rename is ambiguous.
	 *
	 * @param IOutput    $output Repair output.
	 * @param SlugRename $rename The slug and its new name.
	 *
	 * @return void
	 */
	private function renameOne(IOutput $output, SlugRename $rename): void {
		$rename->old = $this->schemaIds(slug: $rename->from);
		$rename->new = $this->schemaIds(slug: $rename->to);

		if ($this->hasOneToRename(output: $output, rename: $rename) === false) {
			return;
		}

		if ($this->isUnambiguous(output: $output, rename: $rename) === false) {
			return;
		}

		$this->applyRename(output: $output, rename: $rename);
	}//end renameOne()

	/**
	 * Whether the schema table could be read and holds this app's old slug; says so when not.
	 *
	 * @param IOutput    $output Repair output.
	 * @param SlugRename $rename The slug, with the ids found under both names.
	 *
	 * @return bool True when there is a schema to rename.
	 */
	private function hasOneToRename(IOutput $output, SlugRename $rename): bool {
		if ($rename->old === null || $rename->new === null) {
			$output->info('RenameCollidingSchemaSlugs: schema table unreadable; leaving `' . $rename->from . '` alone.');
			return false;
		}

		if ($rename->old === []) {
			$output->info('RenameCollidingSchemaSlugs: no dossiq-owned `' . $rename->from . '`; nothing to do.');
			return false;
		}

		return true;
	}//end hasOneToRename()

	/**
	 * Whether exactly one schema carries the old slug and none the new one; warns and logs when not.
	 *
	 * Both slugs present: each may own objects, and renaming would collide with
	 * the new row. Abandoning either set is not a call a repair step gets to make
	 * without being asked.
	 *
	 * @param IOutput    $output Repair output.
	 * @param SlugRename $rename The slug, with the ids found under both names.
	 *
	 * @return bool True when the rename is unambiguous.
	 */
	private function isUnambiguous(IOutput $output, SlugRename $rename): bool {
		if ($rename->new !== []) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
				['from' => $rename->from, 'to' => $rename->to, 'old' => $rename->old, 'new' => $rename->new]
			);
			$output->warning(
				'RenameCollidingSchemaSlugs: both `' . $rename->from . '` and `' . $rename->to
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return false;
		}

		if (count((array)$rename->old) > 1) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
				['from' => $rename->from, 'ids' => $rename->old]
			);
			$output->warning('RenameCollidingSchemaSlugs: duplicate `' . $rename->from . '` schemas; refusing to guess.');
			return false;
		}

		return true;
	}//end isUnambiguous()

	/**
	 * Rewrite the one schema's slug in place, keeping its id; say what happened.
	 *
	 * @param IOutput    $output Repair output.
	 * @param SlugRename $rename The slug, with the one id found under the old name.
	 *
	 * @return void
	 */
	private function applyRename(IOutput $output, SlugRename $rename): void {
		$id = ((array)$rename->old)[0];
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[$rename->to, $id]
			);
		} catch (Exception $e) {
			// Safe to fail: the import then creates a new schema rather than
			// updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
				['from' => $rename->from, 'id' => $id, 'exception' => $e->getMessage()]
			);
			$output->warning('RenameCollidingSchemaSlugs: renaming `' . $rename->from . '` failed; see the log.');
			return;
		}

		$output->info(
			'RenameCollidingSchemaSlugs: schema ' . $id . ' renamed `' . $rename->from . '` -> `' . $rename->to
			. '` (collided with ' . $rename->with . '); its objects stay attached.'
		);
	}//end applyRename()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * Scoped to THIS app's rows. Without the application filter the lookup would
	 * find shillinq's `SupplierInvoice` too, and renaming that is precisely the
	 * damage this step exists to avoid.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			// `fetchAll(PDO::FETCH_COLUMN)` already hands back a list, so the
			// array_values() that used to wrap this was a no-op.
			return (array)$rows;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
