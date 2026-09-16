<?php

/**
 * Publish what a case type's roles may see and change, onto the case schema.
 *
 * The role half of the field rules. The status half
 * ({@see \OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector}) writes the
 * lifecycle `states` block; this one writes `properties.<field>.authorization`,
 * which is where OpenRegister's `PropertyRbacHandler` reads field-level
 * security from. The two are published at the same moment, from the same case
 * type, and enforced by the same app.
 *
 * 🔴 THE PROPERTY BLOCK IS WHAT WITHHOLDS THE FIELD FROM A ROLE'S READ. A
 * lifecycle rule only applies while the case is in a state the case type
 * declares. A case sitting in a state nobody declared — a type mid-edit, a
 * status row deleted, a case migrated in — would hand the field to exactly the
 * role the rule exists to keep it from, and nothing would report it. The
 * property block has no state in it, so it answers in every state and on every
 * read path OpenRegister has: single get, list, search, export and nested
 * expansion all pass through `filterReadableProperties()`.
 *
 * 🔴 IT MERGES PER PROPERTY AND NEVER REPLACES THE BLOCK. Every case type on
 * the instance projects onto the same `case` schema, and the register JSON
 * declares authorization of its own on that schema (`riskAssessment` and
 * `riskLevel`, register.d/38-markers-and-assessments.json). A writer that
 * replaced a property's authorization would silently unpublish the other case
 * types' rules, or the register's, and nothing would error: the field would
 * simply start being returned again.
 *
 * 🔴 THE LEDGER IS WHAT LETS A RULE COME OFF AGAIN. Merging alone can only ever
 * add, so a case type whose last rule was deleted would keep restricting the
 * field forever. `configuration.x-dossiq-field-roles` records what each case
 * type currently projects, keyed by case type uuid, so a publish can drop what
 * that type no longer declares and leave every other key alone. It lives in
 * `configuration` rather than beside the grants because a marker inside a grant
 * is a key OpenRegister does not know, and an unknown key is dropped in
 * silence — a provenance marker that disappears is worse than none.
 *
 * What it costs when the ledger is lost: an import that clears the schema
 * configuration takes the ledger with it, and the next publish cannot tell
 * which grants were its own. The grants stay on the properties, so the failure
 * is a rule that outlives its declaration — a field withheld from somebody who
 * should have got it back. That is visible and complainable, which is the
 * direction a security rule should fail in; the same import wiping the
 * PROPERTIES instead would hand the field back to everyone in silence, which is
 * why {@see reapply()} runs on every reconcile.
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
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a case type's per-role field rules onto the live case schema.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
class CaseFieldRoleProjector {

	/**
	 * The schema the rules are published onto.
	 */
	public const CASE_SCHEMA_SLUG = 'case';

	/**
	 * The configuration key recording what each case type currently projects.
	 */
	public const LEDGER_KEY = 'x-dossiq-field-roles';

	/**
	 * The property key OpenRegister reads field-level security from.
	 */
	public const AUTHORIZATION_KEY = 'authorization';

	/**
	 * OpenRegister's schema mapper, by the name the container knows it under.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore          $store       Reads the case type row.
	 * @param FieldRoleRuleDeclaration $declaration Turns one case type's rules into OpenRegister's shape.
	 * @param SchemaSlugResolver     $slugs       Resolves the case schema inside our own register.
	 * @param RegisterFragmentMerger $fragments   Folds the register fragments onto the base JSON.
	 * @param ContainerInterface     $container   The DI container, for OpenRegister's SchemaMapper.
	 * @param LoggerInterface        $logger      The logger.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly FieldRoleRuleDeclaration $declaration,
		private readonly SchemaSlugResolver $slugs,
		private readonly RegisterFragmentMerger $fragments,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish one case type's role rules onto the live case schema.
	 *
	 * Answers whether the schema was written. A failure is logged and answered
	 * false: publication of a case type must not fail because an instance's
	 * OpenRegister cannot take the block, and the declarations survive on the
	 * row for the next publish.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return bool True when the schema was rewritten.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function publish(string $caseTypeId): bool {
		if ($caseTypeId === '') {
			return false;
		}

		$own = $this->declaration->propertyAuthorization(
			caseType: $this->store->readCaseType(caseTypeId: $caseTypeId)
		);

		return $this->write(caseTypeId: $caseTypeId, own: $own);
	}//end publish()

	/**
	 * Put the ledger's grants back onto the properties.
	 *
	 * Called after the register import, which rewrites a schema's `properties`
	 * from the register JSON and therefore drops every grant this class
	 * projected. Nothing errors when that happens: the field simply starts
	 * being returned to the role it was withheld from, on every read, until
	 * somebody happens to publish the case type again. That is the silent
	 * disclosure this method exists to close, and it is why it runs on every
	 * reconcile rather than on a version gate.
	 *
	 * @return bool True when the schema was rewritten.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function reapply(): bool {
		return $this->write(caseTypeId: '', own: []);
	}//end reapply()

	/**
	 * The ledger with one case type's entry brought up to date.
	 *
	 * Public because it is the half of this class that cannot be seen from the
	 * outside, and the failure it prevents — another case type's rules
	 * disappearing — is invisible from the editor. An empty projection REMOVES
	 * the key rather than writing an empty block, so a case type that declares
	 * nothing leaves nothing behind for the next reader to carry forward as if
	 * it meant something.
	 *
	 * @param array<string, mixed> $ledger     The ledger as it stands.
	 * @param string               $caseTypeId The case type being published.
	 * @param array<string, mixed> $own        What that case type now projects.
	 *
	 * @return array<string, mixed> The ledger.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function ledgerWith(array $ledger, string $caseTypeId, array $own): array {
		if ($caseTypeId === '') {
			return $ledger;
		}

		unset($ledger[$caseTypeId]);
		if ($own !== []) {
			$ledger[$caseTypeId] = $own;
		}

		return $ledger;
	}//end ledgerWith()

	/**
	 * The schema properties carrying the register's grants plus the ledger's.
	 *
	 * Only a property NAMED by one of the two is touched. A property whose
	 * authorization came from somewhere else entirely keeps it, because this
	 * class knows what it declared and must not infer ownership of what it did
	 * not.
	 *
	 * A property that ends up with no grants at all loses the `authorization`
	 * key rather than keeping an empty one. `read: []` is a non-empty
	 * authorization block holding nobody, so leaving it behind would strip the
	 * field for every non-administrator on the instance while the case type
	 * declares nothing at all.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param array<string, mixed> $base       The authorization the register JSON declares.
	 * @param array<string, mixed> $ledger     What every case type projects now.
	 * @param array<string, mixed> $previous   What every case type projected before.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function propertiesWith(
		array $properties,
		array $base,
		array $ledger,
		array $previous = []
	): array {
		// 🔴 THE FIELDS THE LEDGER HAS JUST STOPPED OWNING HAVE TO BE VISITED
		// TOO, OR A RULE CAN BE ADDED AND NEVER TAKEN OFF. A withdrawal removes
		// the field from the new ledger, so a loop over the new ledger alone
		// never reaches the property and the grant written for a rule nobody
		// declares any more stays on the schema for ever. Seeding those fields
		// with an empty block is what makes the withdrawal a write.
		$wanted = $this->withdrawn(ledger: $ledger, previous: $previous);
		foreach ($base as $field => $block) {
			$wanted[$field] = $block;
		}

		foreach ($ledger as $own) {
			if (is_array($own) === false) {
				continue;
			}

			foreach ($own as $field => $block) {
				if (is_array($block) === false) {
					continue;
				}

				$wanted[$field] = $this->mergeBlocks(
					current: ($wanted[$field] ?? []),
					added: $block
				);
			}
		}

		foreach ($wanted as $field => $block) {
			if (is_array(($properties[$field] ?? null)) === false) {
				// A rule naming a property the case schema does not declare is
				// left unpublished rather than invented: OpenRegister refuses a
				// whole schema save over an authorization block on a property
				// it cannot find, and one stale rule must not make every other
				// rule on the schema unpublishable.
				continue;
			}

			if ($block === []) {
				unset($properties[$field][self::AUTHORIZATION_KEY]);
				continue;
			}

			$properties[$field][self::AUTHORIZATION_KEY] = $block;
		}

		return $properties;
	}//end propertiesWith()

	/**
	 * The authorization the register JSON declares on the case schema.
	 *
	 * Read from the fragment-merged JSON rather than subtracted from the live
	 * schema. Subtracting the ledger from what is live would work until a case
	 * type happened to name the same group the register already grants: the
	 * group would be counted as projected, withdrawing the case type's rule
	 * would take the register's own grant with it, and `riskAssessment` would
	 * quietly become readable by everyone.
	 *
	 * @return array<string, mixed> The declared blocks, by property name.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function registerBase(): array {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			return [];
		}

		$configData = json_decode((string)file_get_contents($configPath), true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return [];
		}

		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);

		$properties = ($configData['components']['schemas'][self::CASE_SCHEMA_SLUG]['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$base = [];
		foreach ($properties as $field => $definition) {
			$block = ($definition[self::AUTHORIZATION_KEY] ?? null);
			if (is_array($block) === true && $block !== []) {
				$base[(string)$field] = $block;
			}
		}

		return $base;
	}//end registerBase()

	/**
	 * The fields the ledger owned a moment ago and does not own now.
	 *
	 * Each answers an EMPTY block, which the caller reads as "clear it". A
	 * field the register JSON still declares is written back over that empty
	 * block a line later, so a withdrawal never takes the register's own grant
	 * with it.
	 *
	 * @param array<string, mixed> $ledger   What every case type projects now.
	 * @param array<string, mixed> $previous What every case type projected before.
	 *
	 * @return array<string, array<string, mixed>> The fields to clear.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function withdrawn(array $ledger, array $previous): array {
		$owned = [];
		foreach ($ledger as $own) {
			if (is_array($own) === true) {
				$owned = array_merge($owned, array_keys($own));
			}
		}

		$clear = [];
		foreach ($previous as $own) {
			if (is_array($own) === false) {
				continue;
			}

			foreach (array_keys($own) as $field) {
				if (in_array($field, $owned, true) === false) {
					$clear[$field] = [];
				}
			}
		}

		return $clear;
	}//end withdrawn()

	/**
	 * Two authorization blocks as one, verb by verb.
	 *
	 * @param array<string, mixed> $current The block already gathered.
	 * @param array<string, mixed> $added   The block to add.
	 *
	 * @return array<string, mixed> The merged block.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function mergeBlocks(array $current, array $added): array {
		foreach ($added as $verb => $grants) {
			if (is_array($grants) === false) {
				continue;
			}

			$held = ($current[$verb] ?? []);
			if (is_array($held) === false) {
				$held = [];
			}

			$current[$verb] = $this->declaration->union(current: $held, added: $grants);
		}

		return $current;
	}//end mergeBlocks()

	/**
	 * Write the ledger and the properties back, answering whether it landed.
	 *
	 * @param string               $caseTypeId The case type being published, or '' for a reapply.
	 * @param array<string, mixed> $own        What that case type now projects.
	 *
	 * @return bool True when the schema was written.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function write(string $caseTypeId, array $own): bool {
		// 🔴 ASKED, NOT CAUGHT. An absent OpenRegister is the ordinary state of
		// an instance that does not run one, and answering it with a swallowed
		// exception makes "this app is not installed" indistinguishable from
		// "the write failed" in the logs. `has()` asks the question directly.
		if ($this->container->has(self::SCHEMA_MAPPER) === false) {
			$this->logger->info(
				'Dossiq: no OpenRegister SchemaMapper, so the per-role field rules were not published'
			);
			return false;
		}

		try {
			return $this->writeToSchema(caseTypeId: $caseTypeId, own: $own);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not publish the per-role field rules onto the case schema',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end write()

	/**
	 * Compute and store the ledger and the properties, letting failure throw.
	 *
	 * Split from {@see write()} so the whole gesture, the container lookup
	 * included, sits inside ONE try. A resolve outside it answered null from a
	 * catch of its own, which reads in the logs as an app that is not installed
	 * whatever actually went wrong.
	 *
	 * @param string               $caseTypeId The case type being published, or '' for a reapply.
	 * @param array<string, mixed> $own        What that case type now projects.
	 *
	 * @return bool True when the schema was written.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function writeToSchema(string $caseTypeId, array $own): bool {
		$schemaMapper = $this->container->get(self::SCHEMA_MAPPER);
		if (is_object($schemaMapper) === false) {
			return false;
		}

		$schema = $this->slugs->resolve(schemaMapper: $schemaMapper, slug: self::CASE_SCHEMA_SLUG);
		if ($schema === null) {
			return false;
		}

		$configuration = $this->arrayOf(value: $schema->getConfiguration());
		$properties = $this->arrayOf(value: $schema->getProperties());

		$ledger = $this->arrayOf(value: ($configuration[self::LEDGER_KEY] ?? []));
		$updatedLedger = $this->ledgerWith(ledger: $ledger, caseTypeId: $caseTypeId, own: $own);

		$updatedProperties = $this->propertiesWith(
			properties: $properties,
			base: $this->registerBase(),
			ledger: $updatedLedger,
			previous: $ledger
		);

		if ($updatedLedger === $ledger && $updatedProperties === $properties) {
			return false;
		}

		unset($configuration[self::LEDGER_KEY]);
		if ($updatedLedger !== []) {
			$configuration[self::LEDGER_KEY] = $updatedLedger;
		}

		$schema->setConfiguration($configuration);
		$schema->setProperties($updatedProperties);
		$schemaMapper->update($schema);

		return true;
	}//end writeToSchema()

	/**
	 * One schema column as an array, whatever it answered.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return array<string, mixed> The value.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function arrayOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOf()
}//end class
