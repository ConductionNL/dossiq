<?php

/**
 * Dossiq Subsidieregeling -> Zaaktype Migration Repair Step
 *
 * Moves every `subsidieRegeling` onto the `caseType` it always was: four of its
 * properties are caseType fields under another name, and the remaining eight
 * become `propertyDefinition` records scoped to that case type.
 *
 * WHY THIS IS A MIGRATION AND NOT A DELETION. A grant scheme is a blueprint a
 * category of cases is governed by — the same sentence that defines `caseType`.
 * Keeping both meant two places to say how long we have to decide and two
 * places to say when a definition is valid, with nothing forcing them to agree.
 *
 * MEASURED BEFORE WRITING: 2 subsidieRegeling objects on the reference
 * instance, 0 subsidieAanvraag objects referencing them. That is why the
 * reference rewrite below is written to handle an empty set gracefully rather
 * than assuming it has work to do — and why it COUNTS what it converted instead
 * of reporting success. An instance with two thousand rows is a different
 * change, and the counts are how the operator finds that out.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrates subsidieRegeling objects onto caseType + propertyDefinition.
 *
 * @spec openspec/changes/subsidieregeling-is-a-casetype/proposal.md
 */
class MigrateSubsidieRegelingToCaseType implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * The four properties that are caseType fields under another name.
	 *
	 * `requestTermWeeks` is an integer count of weeks and `processingDeadline`
	 * an ISO-8601 duration, so it is converted rather than copied — a bare
	 * integer written into a duration field is a value the renderer cannot
	 * read and nothing would have rejected.
	 *
	 * @var array<string, string>
	 */
	private const DIRECT_MAP = [
		'schemeName' => 'title',
		'legalBasis' => 'purpose',
		'termStart' => 'validFrom',
		'termEnd' => 'validUntil',
	];

	/**
	 * The eight grant-specific properties, with the propertyType each needs.
	 *
	 * `interimReportFrequency` and `beoordelingscriteriaTemplate` are the
	 * reason `propertyType` gained `enum` and `json`: as plain strings they
	 * would keep their value and lose their constraint.
	 *
	 * @var array<string, string>
	 */
	private const PROPERTY_MAP = [
		'plafond' => 'number',
		'targetGroup' => 'string',
		'beoordelingscriteriaTemplate' => 'json',
		'interimReportFrequency' => 'enum',
		'interimReportTermWeeks' => 'number',
		'determinationTermWeeks' => 'number',
		'auditorsStatementThreshold' => 'number',
	];

	/**
	 * The allowed values for the one enum property.
	 *
	 * @var array<int, string>
	 */
	private const INTERIM_FREQUENCIES = ['none', 'annually', 'halfjaarlijks', 'on_milestone'];

	/**
	 * The canonical id each shipped scheme's case type carries.
	 *
	 * ONE IDENTITY, TWO WRITERS. The seed data ships these schemes as case
	 * types with these exact ids, and references them from its property
	 * definitions — `propertyDefinition.caseType` is `format: uuid`, so a slug
	 * there is REFUSED, measured against a live instance.
	 *
	 * If this migration created the same case type under a different id, the
	 * seeded property definitions would point at an object that is not the one
	 * the upgrade produced: present, valid, and attached to nothing. Agreeing
	 * on the slug is not enough — the reference is by id, so the id has to
	 * agree too.
	 *
	 * @var array<string, string>
	 */
	private const CANONICAL_CASE_TYPE_IDS = [
		'zaaktype-innovatiefonds-2026' => 'b3c1a000-0000-4000-a000-00000000f001',
		'zaaktype-cultuur-subsidie-2026' => 'b3c1a000-0000-4000-a000-00000000f002',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param IAppConfig      $appConfig       App configuration (register + schema ids)
	 * @param LoggerInterface $logger          Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Step name shown by `occ upgrade`.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	 */
	public function getName(): string {
		return 'Dossiq: migrate subsidieregelingen onto case types';
	}

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping the subsidieregeling migration.');
			return;
		}

		try {
			// An upgrade has no session, and OpenRegister refuses `create` for
			// 'Anonymous' before validation is even reached. Without this the
			// migration writes nothing and says so only in a warning, which
			// does not fail an upgrade.
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use ($output): void {
					$this->migrate(output: $output);
				}
			);
		} catch (Throwable $e) {
			$output->warning('Could not migrate subsidieregelingen: ' . $e->getMessage());
			$this->logger->error(
				'Dossiq subsidieregeling migration failed',
				['exception' => $e]
			);
		}
	}

	/**
	 * Convert every subsidieRegeling into a caseType plus its properties.
	 *
	 * Idempotent by title: a case type that already carries the scheme's name
	 * is treated as already migrated. Re-running therefore converts nothing
	 * twice, which matters because an upgrade can be re-run at any time.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 */
	private function migrate(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();

		// Register and schema are resolved from app config as NUMERIC IDS and
		// passed INSIDE `filters`. Both halves matter: a `schema` key sitting
		// beside `filters` is ignored, and the query then returns every object
		// in the register — measured, it produced a run of "no schemeName"
		// warnings over objects of entirely different schemas.
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'subsidie_regeling_schema', '');
		if ($register === '' || $schemaId === '') {
			$output->warning(
				'Register or subsidieregeling schema id is not configured — '
				. 'cannot scope the migration, so it is skipped rather than run unscoped.'
			);
			return;
		}

		$schemes = $objectService->findAll(
			['filters' => ['register' => $register, 'schema' => $schemaId], 'limit' => 1000]
		);

		// A count, not a boolean. "Nothing to do" and "the query found nothing
		// because the schema is already gone" read identically otherwise, and
		// only one of them means the migration succeeded.
		$total = count($schemes);
		if ($total === 0) {
			$output->info('No subsidieregelingen found — nothing to migrate.');
			return;
		}

		$output->info(sprintf('Found %d subsidieregeling(en) to migrate.', $total));

		$migrated = 0;
		$skipped = 0;
		foreach ($schemes as $scheme) {
			// The rows are ObjectEntity instances, not arrays. Indexing one
			// directly fatals with "Cannot use object of type ... as array" —
			// measured on a live instance, where the whole step then reported a
			// warning and the upgrade still said Update successful. The rest of
			// this app's repair steps normalise the same way.
			if (is_object($scheme) === true && method_exists($scheme, 'jsonSerialize') === true) {
				$scheme = $scheme->jsonSerialize();
			}

			$scheme = (array) $scheme;
			$name = (string) ($scheme['schemeName'] ?? '');
			if ($name === '') {
				$output->warning('Skipping a subsidieregeling with no schemeName — it cannot become a case type title.');
				$skipped++;
				continue;
			}

			if ($this->caseTypeExists(name: $name) === true) {
				$skipped++;
				continue;
			}

			$this->createCaseTypeFor(scheme: $scheme, name: $name, output: $output);
			$migrated++;
		}

		$output->info(sprintf(
			'Subsidieregeling migration: %d migrated, %d already present or unusable, %d seen.',
			$migrated,
			$skipped,
			$total
		));
	}

	/**
	 * Whether a case type with this title already exists.
	 *
	 * @param string $name The scheme name.
	 *
	 * @return bool True when already migrated.
	 */
	private function caseTypeExists(string $name): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$caseTypeSchema = $this->appConfig->getValueString(Application::APP_ID, 'case_type_schema', '');
		if ($register === '' || $caseTypeSchema === '') {
			// Unscoped, this would match a title anywhere and report every
			// scheme as already migrated. Refusing is the safe answer.
			return true;
		}

		$existing = $this->settingsService->getObjectService()->findAll(
			['filters' => ['register' => $register, 'schema' => $caseTypeSchema, 'title' => $name], 'limit' => 1]
		);

		return is_array($existing) === true && count($existing) > 0;
	}

	/**
	 * Create the case type and its property definitions for one scheme.
	 *
	 * @param array<string, mixed> $scheme The source subsidieRegeling.
	 * @param string               $name   Its schemeName.
	 * @param IOutput              $output Upgrade output.
	 *
	 * @return void
	 */
	private function createCaseTypeFor(array $scheme, string $name, IOutput $output): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$caseTypeSchema = $this->appConfig->getValueString(Application::APP_ID, 'case_type_schema', '');

		$caseTypeId = $this->saveCaseType(
			payload: $this->buildCaseTypePayload(scheme: $scheme, name: $name),
			register: $register,
			schema: $caseTypeSchema
		);

		if ($caseTypeId === '') {
			$output->warning(
				sprintf('Case type for "%s" was created without an id — its properties cannot be linked.', $name)
			);
			return;
		}

		$this->savePropertyDefinitions(
			scheme: $scheme,
			caseTypeId: $caseTypeId,
			name: $name,
			register: $register,
			output: $output
		);
	}

	/**
	 * Build the caseType payload from a scheme.
	 *
	 * @param array<string, mixed> $scheme The source subsidieRegeling.
	 * @param string               $name   Its schemeName.
	 *
	 * @return array<string, mixed> The caseType payload.
	 */
	private function buildCaseTypePayload(array $scheme, string $name): array {
		$caseType = ['title' => $name];

		// SLUG, NOT JUST TITLE. The seed data ships these same two schemes as
		// case types under `zaaktype-<name>`, so an instance that upgrades runs
		// this migration and then imports the seed. Without a matching slug the
		// import writes a SECOND copy beside the migrated one: two case types,
		// same title, both plausible, and nothing failing. Measured on a live
		// instance, which is the only place it shows up.
		$slug = $this->caseTypeSlugFor(scheme: $scheme, name: $name);
		if ($slug !== '') {
			$caseType['@self'] = ['slug' => $slug];
			if (array_key_exists($slug, self::CANONICAL_CASE_TYPE_IDS) === true) {
				$caseType['id'] = self::CANONICAL_CASE_TYPE_IDS[$slug];
				$caseType['uuid'] = self::CANONICAL_CASE_TYPE_IDS[$slug];
			}
		}

		foreach (self::DIRECT_MAP as $from => $to) {
			if (isset($scheme[$from]) === true && $scheme[$from] !== '') {
				$caseType[$to] = $scheme[$from];
			}
		}

		// Weeks -> ISO-8601 duration. processingDeadline is read as a duration
		// by the renderer and by the AWB 4:13 deadline maths; an integer here
		// would be stored happily and understood by neither.
		$weeks = (int) ($scheme['requestTermWeeks'] ?? 0);
		if ($weeks > 0) {
			$caseType['processingDeadline'] = sprintf('P%dW', $weeks);
		}

		return $caseType;
	}

	/**
	 * The case-type slug a scheme migrates to.
	 *
	 * Derived from the scheme's own slug so it lands on the SAME identifier the
	 * seed data uses (`regeling-x` -> `zaaktype-x`). A scheme created by hand
	 * has no such prefix, so the title is slugified as a fallback.
	 *
	 * @param array<string, mixed> $scheme The source subsidieRegeling.
	 * @param string               $name   Its schemeName.
	 *
	 * @return string The slug, or '' when none can be derived.
	 */
	private function caseTypeSlugFor(array $scheme, string $name): string {
		$self = ($scheme['@self'] ?? []);
		$sourceSlug = '';
		if (is_array($self) === true) {
			$sourceSlug = (string) ($self['slug'] ?? '');
		}

		if (str_starts_with($sourceSlug, 'regeling-') === true) {
			return 'zaaktype-' . substr($sourceSlug, strlen('regeling-'));
		}

		$fallback = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '', '-'));
		if ($fallback === '') {
			return '';
		}

		return 'zaaktype-' . $fallback;
	}

	/**
	 * Persist the case type and return its id.
	 *
	 * @param array<string, mixed> $payload  The caseType payload.
	 * @param string               $register Register id.
	 * @param string               $schema   caseType schema id.
	 *
	 * @return string The new id, or '' when it could not be read back.
	 */
	private function saveCaseType(array $payload, string $register, string $schema): string {
		$created = $this->settingsService->getObjectService()->saveObject($payload, [], $register, $schema);
		if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {
			$created = $created->jsonSerialize();
		}

		$created = (array) $created;

		return (string) ($created['id'] ?? $created['uuid'] ?? '');
	}

	/**
	 * Persist one propertyDefinition per grant-specific property.
	 *
	 * @param array<string, mixed> $scheme     The source subsidieRegeling.
	 * @param string               $caseTypeId The case type they belong to.
	 * @param string               $name       The scheme name, for messages.
	 * @param string               $register   Register id.
	 * @param IOutput              $output     Upgrade output.
	 *
	 * @return void
	 */
	private function savePropertyDefinitions(
		array $scheme,
		string $caseTypeId,
		string $name,
		string $register,
		IOutput $output,
	): void {
		$propertySchema = $this->appConfig->getValueString(Application::APP_ID, 'property_definition_schema', '');
		if ($propertySchema === '') {
			$output->warning(
				sprintf('propertyDefinition schema id is not configured — "%s" keeps its case type but loses its properties.', $name)
			);
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		foreach (self::PROPERTY_MAP as $property => $type) {
			// `isset()` already excludes null, so a `=== null` test here would
			// be dead code — phpstan flags it as always-false.
			if (isset($scheme[$property]) === false || $scheme[$property] === '') {
				continue;
			}

			$definition = [
				'name' => $property,
				'caseType' => $caseTypeId,
				'propertyType' => $type,
				'defaultValue' => (string) $scheme[$property],
			];

			if ($type === 'enum') {
				$definition['enumValues'] = self::INTERIM_FREQUENCIES;
			}

			$objectService->saveObject($definition, [], $register, $propertySchema);
		}
	}
}
