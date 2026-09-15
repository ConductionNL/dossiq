<?php

/**
 * Publish what a case type's statuses ask of the fields, onto the case schema.
 *
 * OpenRegister enforces field rules by STATE, read from
 * `x-openregister-lifecycle.states.<state>.fields` on the schema being saved.
 * dossiq's states are not in that schema: `case.status` is a reference to a
 * `statusType` ROW, and every case type brings its own rows. So the states
 * block cannot be shipped in `dossiq_register.json` the way every other
 * lifecycle in this app is. It has to be projected at publish time, from the
 * rows, onto the live schema.
 *
 * 🔴 THE STATE KEY IS THE statusType UUID, because that is the value
 * `case.status` carries and therefore the value OpenRegister's
 * `StateFieldRuleResolver::stateOf()` reads out of the object. Keying on the
 * status NAME would look right in the editor and match nothing at runtime, and
 * two case types are allowed to call a status the same thing.
 *
 * 🔴 IT MERGES PER STATE AND NEVER REPLACES THE BLOCK. Every case type on the
 * instance projects onto the same `case` schema. A writer that replaced
 * `states` would silently delete the rules of every OTHER case type each time
 * one was published, and nothing would report it: the rules would simply stop
 * being enforced. So this writes the states this case type owns, drops the
 * ones it owns and no longer declares, and leaves every other key alone.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a case type's per-status field rules onto the live case schema.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class CaseStateFieldRuleProjector {

	/**
	 * The schema the rules are published onto.
	 */
	public const CASE_SCHEMA_SLUG = 'case';

	/**
	 * The annotation block the states live under.
	 */
	public const LIFECYCLE_KEY = 'x-openregister-lifecycle';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore                $store       Reads the statusType and propertyDefinition rows.
	 * @param StatusFieldRuleDeclaration   $declaration Turns one status's rules into OpenRegister's shape.
	 * @param SchemaSlugResolver           $slugs       Resolves the case schema inside our own register.
	 * @param ContainerInterface           $container   The DI container, for OpenRegister's SchemaMapper.
	 * @param LoggerInterface              $logger      The logger.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly StatusFieldRuleDeclaration $declaration,
		private readonly SchemaSlugResolver $slugs,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The states one case type declares, keyed by statusType uuid.
	 *
	 * Pure: it reads rows and returns a block, so the whole projection is
	 * testable without a schema mapper. Two sources feed it, and they are one
	 * declaration rather than two mechanisms:
	 *
	 * - a status's own `fieldRules`, which is the rule with the groups, the
	 *   condition and the message;
	 * - a property's `requiredAtStatus`, which has existed in the Properties
	 *   tab since case types shipped, has been written by administrators ever
	 *   since, and until now was enforced by nothing at all. It is folded in as
	 *   a plain `required` entry, so the control that already exists starts
	 *   meaning something instead of gaining a rival that contradicts it.
	 *
	 * A status the case type declares nothing for gets no entry, which keeps
	 * the block the size of what was actually declared.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<string, array<string, mixed>> The states block.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function statesOf(string $caseTypeId): array {
		$statusTypes = $this->store->rowsOfType(
			schemaKey: 'status_type_schema',
			caseTypeId: $caseTypeId
		);

		$requiredByStatus = $this->requiredByStatus(caseTypeId: $caseTypeId);

		$states = [];
		foreach ($statusTypes as $statusType) {
			$stateKey = $this->store->rowId(row: $statusType);
			if ($stateKey === '') {
				continue;
			}

			$fields = $this->declaration->fieldsBlock(statusType: $statusType);
			$fields = $this->foldInRequired(
				fields: $fields,
				required: ($requiredByStatus[$stateKey] ?? [])
			);

			if ($fields === []) {
				continue;
			}

			$states[$stateKey] = ['fields' => $fields];
		}

		return $states;
	}//end statesOf()

	/**
	 * Publish a case type's states onto the live case schema.
	 *
	 * Answers whether the schema was written, so a caller can say "nothing
	 * changed" without asking again. A failure is logged and answered false:
	 * publication of a case type must not fail because an instance's
	 * OpenRegister cannot take the block, and the declarations survive on the
	 * rows for the next publish.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return bool True when the schema configuration was rewritten.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function publish(string $caseTypeId): bool {
		if ($caseTypeId === '') {
			return false;
		}

		$ownKeys = $this->stateKeysOf(caseTypeId: $caseTypeId);
		$states = $this->statesOf(caseTypeId: $caseTypeId);

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: no OpenRegister SchemaMapper, so per-status field rules were not published',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		// A container that answers with something other than a mapper is the
		// same situation as one that throws: this instance has no OpenRegister
		// to publish onto. Answering false is the honest reading, and it keeps
		// the type error out of a publish that is otherwise complete.
		if (is_object($schemaMapper) === false) {
			return false;
		}

		$schema = $this->slugs->resolve(schemaMapper: $schemaMapper, slug: self::CASE_SCHEMA_SLUG);
		if ($schema === null) {
			return false;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$lifecycle = ($configuration[self::LIFECYCLE_KEY] ?? []);
		if (is_array($lifecycle) === false) {
			$lifecycle = [];
		}

		$merged = $this->mergeStates(
			live: (is_array(($lifecycle['states'] ?? null)) === true ? $lifecycle['states'] : []),
			own: $states,
			ownKeys: $ownKeys
		);

		if ($merged === ($lifecycle['states'] ?? null)) {
			return false;
		}

		if ($merged === []) {
			unset($lifecycle['states']);
		} else {
			$lifecycle['states'] = $merged;
		}

		$configuration[self::LIFECYCLE_KEY] = $lifecycle;

		try {
			$schema->setConfiguration($configuration);
			$schemaMapper->update($schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not publish per-status field rules onto the case schema',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end publish()

	/**
	 * Keep a projected `states` block across a reconcile of the lifecycle.
	 *
	 * 🔴 WITHOUT THIS THE RECONCILE SILENTLY UNPUBLISHES EVERY PER-STATUS FIELD
	 * RULE ON THE INSTANCE. {@see \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler}
	 * copies each annotation block wholesale from the register JSON onto the live
	 * schema, because OpenRegister's importer does not round-trip them. The
	 * states this class writes are not in that JSON and cannot be: they are keyed
	 * by statusType UUID, which only exists on a running instance. So a plain
	 * copy replaces a lifecycle carrying states with one carrying none, nothing
	 * errors, and OpenRegister simply stops refusing the saves the rules exist to
	 * refuse.
	 *
	 * It lives here rather than in the reconciler because this class owns the
	 * key. A register JSON that starts declaring `states` for a schema stays the
	 * authority for that schema, which is the promise every other annotation
	 * block already makes.
	 *
	 * @param string $annotationKey The annotation block being reconciled.
	 * @param mixed  $declared      The block as the register JSON declares it.
	 * @param mixed  $live          The block as it stands on the live schema.
	 *
	 * @return mixed The block to write.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public static function carryForwardStates(string $annotationKey, mixed $declared, mixed $live): mixed {
		if ($annotationKey !== self::LIFECYCLE_KEY || is_array($declared) === false) {
			return $declared;
		}

		if (array_key_exists('states', $declared) === true || is_array($live) === false) {
			return $declared;
		}

		$states = ($live['states'] ?? null);
		if (is_array($states) === false || $states === []) {
			return $declared;
		}

		$declared['states'] = $states;

		return $declared;
	}//end carryForwardStates()

	/**
	 * Every statusType uuid this case type owns, declared or not.
	 *
	 * The declared states say what to WRITE. This says what to CLEAR: a status
	 * whose last rule the administrator just deleted has to lose its entry, and
	 * a merge that only ever adds would keep enforcing a rule that is no longer
	 * declared anywhere. That failure is invisible from the editor, which is
	 * why the two lists are gathered separately rather than inferred from each
	 * other.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<int, string> The statusType uuids.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function stateKeysOf(string $caseTypeId): array {
		$keys = [];
		$rows = $this->store->rowsOfType(
			schemaKey: 'status_type_schema',
			caseTypeId: $caseTypeId
		);

		foreach ($rows as $row) {
			$key = $this->store->rowId(row: $row);
			if ($key !== '') {
				$keys[] = $key;
			}
		}

		return $keys;
	}//end stateKeysOf()

	/**
	 * The fields each status requires because a property says so.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<string, array<int, string>> Field names keyed by statusType uuid.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function requiredByStatus(string $caseTypeId): array {
		$properties = $this->store->rowsOfType(
			schemaKey: 'property_definition_schema',
			caseTypeId: $caseTypeId
		);

		$required = [];
		foreach ($properties as $property) {
			$status = $this->store->referenceId(value: ($property['requiredAtStatus'] ?? ''));
			$name = trim((string)($property['name'] ?? ''));
			if ($status === '' || $name === '') {
				continue;
			}

			if (in_array($name, ($required[$status] ?? []), true) === false) {
				$required[$status][] = $name;
			}
		}

		return $required;
	}//end requiredByStatus()

	/**
	 * Add the properties a status requires to its published block.
	 *
	 * A field the status ALREADY requires through its own rule is left alone,
	 * because that rule may carry groups, a condition and a message and this
	 * one carries none of the three. Publishing both would make the
	 * unconditional entry win over the conditional one, which is the opposite
	 * of what the administrator wrote.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $fields   The status's own block.
	 * @param array<int, string>                              $required Fields a property marks required here.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The block.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function foldInRequired(array $fields, array $required): array {
		if ($required === []) {
			return $fields;
		}

		$declared = [];
		foreach (($fields[StatusFieldRuleDeclaration::RULE_REQUIRED] ?? []) as $entry) {
			foreach (($entry['fields'] ?? []) as $name) {
				$declared[] = (string)$name;
			}
		}

		foreach ($required as $name) {
			if (in_array($name, $declared, true) === true) {
				continue;
			}

			$fields[StatusFieldRuleDeclaration::RULE_REQUIRED][] = ['fields' => [$name]];
		}

		return $fields;
	}//end foldInRequired()

	/**
	 * The live states with this case type's own entries brought up to date.
	 *
	 * Public because it is the half of this class that cannot be seen from the
	 * outside: `publish()` needs a live schema to exercise, and the failure this
	 * method prevents is another case type's rules silently disappearing.
	 *
	 * @param array<string, mixed>                $live    The states currently on the schema.
	 * @param array<string, array<string, mixed>> $own     The states this case type declares.
	 * @param array<int, string>                  $ownKeys Every state key this case type owns.
	 *
	 * @return array<string, mixed> The merged states.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function mergeStates(array $live, array $own, array $ownKeys): array {
		$merged = $live;

		foreach ($ownKeys as $key) {
			unset($merged[$key]);
		}

		foreach ($own as $key => $state) {
			$merged[$key] = $state;
		}

		return $merged;
	}//end mergeStates()
}//end class
