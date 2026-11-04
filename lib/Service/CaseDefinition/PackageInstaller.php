<?php

/**
 * Dossiq case definition package installer.
 *
 * Installs a package from disk: validates it, refuses it when this instance
 * lacks a capability it requires, writes all of its rows or none, records
 * every row it wrote in the shipped-set ledger, and applies its settings.
 * Capability C19 of hydra ADR-118: a procedure is configuration, and this is
 * the code that turns the configuration into rows.
 *
 * The public surface takes package ids and returns plain arrays, and the
 * parts are named by what they hold, never by a procedure, so the class can
 * move to OpenRegister mechanically once a second app ships packages
 * (decision 187).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Installs shipped case definition packages.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageInstaller {

	/**
	 * The package was written and recorded.
	 */
	public const INSTALLED = 'installed';

	/**
	 * The package is already at this version here; nothing was written.
	 */
	public const CURRENT = 'current';

	/**
	 * The package was not written, for a reason the package or instance gives.
	 */
	public const REFUSED = 'refused';

	/**
	 * The write failed and was rolled back.
	 */
	public const FAILED = 'error';

	/**
	 * The settings key each case type collection is written to.
	 *
	 * @var array<string, string>
	 */
	private const CASE_TYPE_SCHEMAS = [
		'caseType' => 'case_type_schema',
		'propertyDefinitions' => 'property_definition_schema',
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'resultTypes' => 'result_type_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param PackageDirectory            $packages     Reads the packages from disk.
	 * @param PackageValidator            $validator    Says what is wrong with a package.
	 * @param PackageCapabilities         $capabilities Says what this instance cannot provide.
	 * @param PackageWriter               $writer       Writes all rows or none.
	 * @param ShippedConfigurationService $ledger       Records what was shipped.
	 * @param IAppConfig                  $appConfig    Holds a package's settings.
	 * @param LoggerInterface             $logger       What an install writes about itself.
	 * @param PackageSchemaReferences     $references   Resolves settings that name another app's schema.
	 */
	public function __construct(
		private readonly PackageDirectory $packages,
		private readonly PackageValidator $validator,
		private readonly PackageCapabilities $capabilities,
		private readonly PackageWriter $writer,
		private readonly ShippedConfigurationService $ledger,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly PackageSchemaReferences $references,
	) {
	}//end __construct()

	/**
	 * An installer reading packages from another directory.
	 *
	 * @param string $root The directory that holds the packages.
	 *
	 * @return self The installer.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function fromRoot(string $root): self {
		return new self(
			packages: $this->packages->withRoot(root: $root),
			validator: $this->validator,
			capabilities: $this->capabilities,
			writer: $this->writer,
			ledger: $this->ledger,
			appConfig: $this->appConfig,
			logger: $this->logger,
			references: $this->references,
		);
	}//end fromRoot()

	/**
	 * Every package the directory holds, with its version, install mode and
	 * the version installed here.
	 *
	 * @return array<int, array{id: string, title: string, version: string, install: string, installed: string, valid: bool}>
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function catalogue(): array {
		$catalogue = [];
		foreach ($this->packages->ids() as $id) {
			$package = (array)$this->packages->read(id: $id);
			$validation = $this->validator->validateShipped(package: $package);
			$manifest = (array)($validation['manifest'] ?? []);

			$catalogue[] = [
				'id' => $id,
				'title' => (string)($manifest['title'] ?? ''),
				'version' => (string)($manifest['version'] ?? ''),
				'install' => (string)($manifest['install'] ?? 'auto'),
				'installed' => $this->ledger->installedVersion(set: $id),
				'valid' => $validation['valid'],
			];
		}

		return $catalogue;
	}//end catalogue()

	/**
	 * Install every package whose manifest says it installs on every instance.
	 *
	 * @return array<int, array<string, mixed>> One outcome per package, in id order.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function installAutomatic(): array {
		return $this->installEach(wanted: static fn (array $entry): bool => $entry['install'] === 'auto');
	}//end installAutomatic()

	/**
	 * Install every package that is installed here or installs automatically.
	 *
	 * Each goes through install(), so one already at the shipped version
	 * writes nothing and a newer one refreshes the rows nobody edited. An
	 * on-request package that was never installed is left alone.
	 *
	 * @return array<int, array<string, mixed>> One outcome per package, in id order.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-administrator-reloads-the-shipped-templates
	 */
	public function reloadInstalled(): array {
		return $this->installEach(wanted: static fn (array $entry): bool => $entry['install'] === 'auto' || $entry['installed'] !== '');
	}//end reloadInstalled()

	/**
	 * Install every catalogue package the callback wants, in id order.
	 *
	 * @param callable(array<string, mixed>): bool $wanted Whether a catalogue entry installs.
	 *
	 * @return array<int, array<string, mixed>> One outcome per package installed.
	 */
	private function installEach(callable $wanted): array {
		$entries = array_values(array_filter($this->catalogue(), $wanted));

		return array_map(fn (array $entry): array => $this->install(id: $entry['id']), $entries);
	}//end installEach()

	/**
	 * Install one package.
	 *
	 * A row somebody changed here is kept.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> status, package, version and message; errors
	 *         when refused as invalid, missing when a capability is missing,
	 *         written, skipped and refreshed counts and the ids of the kept rows
	 *         when installed.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function install(string $id): array {
		return $this->installPackage(id: $id, overLocalChanges: false);
	}//end install()

	/**
	 * Install one package, and give every row it shipped that somebody changed
	 * here the keys the package ships; keys it does not ship keep their value.
	 * Installs a package that is current too.
	 *
	 * 🔑 A SECOND NAMED ACT RATHER THAN A FLAG, as
	 * ShippedConfigurationService::adoptOverLocalChange(): an administrator's
	 * work goes, and the caller picks that by name.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> As install().
	 *
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function installOverLocalChanges(string $id): array {
		return $this->installPackage(id: $id, overLocalChanges: true);
	}//end installOverLocalChanges()

	/**
	 * Install one package; see install() and installOverLocalChanges().
	 *
	 * @param string  $id               The package id.
	 * @param boolean $overLocalChanges Whether a row changed here takes the package's keys.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function installPackage(string $id, bool $overLocalChanges): array {
		$package = $this->packages->read(id: $id);
		if ($package === null) {
			return $this->outcome(status: self::REFUSED, id: $id, version: '', message: "No package named '{$id}' is shipped.");
		}

		$validation = $this->validator->validateShipped(package: $package);
		$manifest = (array)($validation['manifest'] ?? []);
		$version = (string)($manifest['version'] ?? '');
		if ($validation['valid'] === false) {
			$refusal = $this->outcome(status: self::REFUSED, id: $id, version: $version, message: "Package '{$id}' is not valid.");

			return $refusal + ['errors' => $validation['errors']];
		}

		$run = new PackageInstallRun(id: $id, manifest: $manifest, package: $package, overLocalChanges: $overLocalChanges);
		$missing = $this->capabilities->missing(requires: $run->requirements());
		if ($missing !== []) {
			return $this->outcome(
				status: self::REFUSED,
				id: $id,
				version: $version,
				message: "Package '{$id}' needs " . implode(', ', $missing) . ', which this instance does not provide.',
			) + ['missing' => $missing];
		}

		if ($overLocalChanges === false && $this->ledger->isPackageCurrent(set: $id, version: $version, digest: $package['digest']) === true) {
			return $this->outcome(status: self::CURRENT, id: $id, version: $version, message: "Package '{$id}' {$version} is already installed.");
		}

		return $this->write(run: $run);
	}//end installPackage()

	/**
	 * Write a valid package whose requirements are met and that is not current, and record it.
	 *
	 * @param PackageInstallRun $run The install.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function write(PackageInstallRun $run): array {
		if ($this->ledger->ledgerAvailable() === false) {
			return $this->failed(
				run: $run,
				message: "Package '{$run->id}' was not installed: the shipped-set ledger is not configured, so its rows could not be recorded.",
			);
		}

		$plan = $this->plan(run: $run);
		if ($plan['missing'] !== []) {
			return $this->outcome(
				status: self::REFUSED,
				id: $run->id,
				version: $run->version,
				message: "Package '{$run->id}' needs " . implode('; ', $plan['reasons']) . '.',
			) + ['missing' => array_values(array_unique($plan['missing']))];
		}

		$result = $this->writer->writePackage(package: $run->id, groups: $plan['groups']);
		if ($result['status'] !== 'success') {
			return $this->failed(run: $run, message: $result['message']);
		}

		if ($this->recordAll(run: $run, result: $result) === false) {
			$this->writer->undo(written: $result['written']);

			return $this->failed(
				run: $run,
				message: "Package '{$run->id}' was not installed: a row could not be recorded in the shipped-set ledger, so every row was taken back.",
			);
		}

		return $this->complete(run: $run, done: ['result' => $result, 'settings' => $plan['settings']]);
	}//end write()

	/**
	 * What a write needs before a row is written: every address the package
	 * names, in a setting or a seed file, looked up on this instance.
	 *
	 * @param PackageInstallRun $run The install.
	 *
	 * @return array{settings: array<string, mixed>, groups: array<int, array<string, mixed>>, missing: array<int, mixed>, reasons: array<int, mixed>}
	 *         The resolved settings, the row groups, and what is missing with why.
	 */
	private function plan(PackageInstallRun $run): array {
		$resolved = $this->references->resolve(settings: $run->settings());
		$groups = $this->groups(run: $run);

		return [
			'settings' => $resolved['settings'],
			'groups' => $groups,
			'missing' => array_merge($resolved['missing'], array_column($groups, 'missing')),
			'reasons' => array_merge($resolved['reasons'], array_column($groups, 'reason')),
		];
	}//end plan()

	/**
	 * Record the written rows, the adopted rows this package has not recorded
	 * before, and the package itself in the shipped-set ledger, stopping at
	 * the first that fails.
	 *
	 * @param PackageInstallRun    $run    The install.
	 * @param array<string, mixed> $result The writer's answer.
	 *
	 * @return boolean True when everything was recorded.
	 */
	private function recordAll(PackageInstallRun $run, array $result): bool {
		return $this->record(run: $run, written: $result['written'])
			&& $this->record(run: $run, written: $this->notYetInThisPackage(id: $run->id, adopted: $result['adopted']))
			&& $this->ledger->recordPackage(set: $run->id, version: $run->version, digest: $run->digest);
	}//end recordAll()

	/**
	 * Fill the adopted rows, refresh the held ones, apply the settings, and
	 * answer what the install did.
	 *
	 * Runs after the write and the ledger record: a filled or refreshed row
	 * changes what was there and cannot be taken back with the rest of a
	 * failed install. An adopted case type only gains fields it lacked.
	 *
	 * @param PackageInstallRun    $run  The install.
	 * @param array<string, mixed> $done The writer's answer (`result`) and the resolved `settings`.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function complete(PackageInstallRun $run, array $done): array {
		$result = $done['result'];
		$filled = $this->writer->fillAdopted(adopted: $result['adopted']);
		$refresh = $this->ledger->refreshRows(
			set: $run->id,
			version: $run->version,
			rows: $result['held'],
			overLocalChanges: $run->overLocalChanges,
		);

		$this->applySettings(settings: $done['settings']);

		$counts = [
			'written' => count($result['written']),
			'adopted' => count($result['adopted']),
			'filled' => count($filled),
			'skipped' => count($result['skipped']),
			'refreshed' => count($refresh['refreshed']),
		];
		$this->logger->info(
			'Installed package {id} {version}: {written} row(s) written, {adopted} adopted ({filled} given fields they lacked),'
			. ' {skipped} already present ({refreshed} refreshed, {kept} kept as this instance holds them)',
			['id' => $run->id, 'version' => $run->version] + $counts + ['kept' => count($refresh['kept'])]
		);

		return $this->outcome(status: self::INSTALLED, id: $run->id, version: $run->version, message: $result['message'])
			+ $counts + ['kept' => $refresh['kept']];
	}//end complete()

	/**
	 * The package's rows, grouped by the settings key of the schema they go to.
	 *
	 * Order matters for references: case type rows first, then flows that
	 * name the case type, then seed rows, each seed file in name order, then
	 * example rows (a case in flight, its dates relative to today) the same way,
	 * on an instance that opted into example data.
	 *
	 * @param PackageInstallRun $run The install.
	 *
	 * @return array<int, array<string, mixed>> The groups: `schemaKey` and `rows`, and for a seed file
	 *                                          `match`, `register` and `schema`, or `missing` and `reason`.
	 */
	private function groups(PackageInstallRun $run): array {
		$groups = [];
		if (in_array('case-type', $run->parts, true) === true) {
			$caseType = (array)json_decode($run->files['case-type.json'], true);
			foreach (self::CASE_TYPE_SCHEMAS as $collection => $schemaKey) {
				$rows = ($caseType[$collection] ?? []);
				if (is_array($rows) === true && $rows !== [] && array_is_list($rows) === false) {
					$rows = [$rows];
				}

				$groups[] = ['schemaKey' => $schemaKey, 'rows' => array_values((array)$rows)];
			}
		}

		if (in_array('flows', $run->parts, true) === true) {
			$groups[] = ['schemaKey' => 'workflow_template_schema', 'rows' => $this->packages->rowsUnder(files: $run->files, dir: 'flows/')];
		}

		if (in_array('seed', $run->parts, true) === true) {
			foreach ($this->packages->rowsUnder(files: $run->files, dir: 'seed/') as $seed) {
				$groups[] = $this->references->seedGroup(seed: $seed);
			}
		}

		array_push($groups, ...$this->exampleGroups(run: $run));

		return array_values(array_filter($groups, static fn (array $group): bool => $group['rows'] !== []));
	}//end groups()

	/**
	 * The groups of the example rows, their date tokens filled relative to
	 * today; none without an `examples` part, and none on an instance that
	 * has not opted into example data.
	 *
	 * @param PackageInstallRun $run The install.
	 *
	 * @return array<int, array<string, mixed>> The groups, as {@see self::groups()} returns them.
	 */
	private function exampleGroups(PackageInstallRun $run): array {
		if (in_array('examples', $run->parts, true) === false) {
			return [];
		}

		$examples = new PackageExamples(appConfig: $this->appConfig, logger: $this->logger);
		$groups = [];
		foreach ($examples->toWrite(package: $run->id, examples: $this->packages->rowsUnder(files: $run->files, dir: 'examples/')) as $example) {
			$groups[] = $this->references->seedGroup(seed: $example);
		}

		return $groups;
	}//end exampleGroups()

	/**
	 * Record every written row in the shipped-set ledger, under the package id.
	 *
	 * @param PackageInstallRun                $run     The install; its package id is the set name.
	 * @param array<int, array<string, mixed>> $written The rows written.
	 *
	 * @return boolean True when every row was recorded.
	 */
	private function record(PackageInstallRun $run, array $written): bool {
		foreach ($written as $row) {
			$stamped = $this->ledger->stamp(
				set: $run->id,
				targetSchema: (string)$row['schema'],
				targetObject: (string)$row['id'],
				object: (array)$row['row'],
				version: $run->version,
				objectsKey: (string)($row['schemaKey'] ?? ''),
			);
			if ($stamped === false) {
				return false;
			}
		}

		return true;
	}//end record()

	/**
	 * The adopted rows this package has not recorded before.
	 *
	 * An adopted row is stamped once, with the row as this instance holds it
	 * at adoption. Stamping it again on every newer version would reset its
	 * fingerprint and hide an edit made here since.
	 *
	 * @param string                           $id      The package id.
	 * @param array<int, array<string, mixed>> $adopted The rows adopted this run.
	 *
	 * @return array<int, array<string, mixed>> The rows to record.
	 */
	private function notYetInThisPackage(string $id, array $adopted): array {
		return array_values(
			array_filter(
				$adopted,
				fn (array $row): bool => $this->ledger->stateOf(
					targetSchema: (string)$row['schema'],
					targetObject: (string)$row['id'],
					object: (array)$row['row'],
				)['set'] !== $id
			)
		);
	}//end notYetInThisPackage()

	/**
	 * Apply the package's settings, each only when this instance never set it.
	 *
	 * An administrator's value, an empty one included, is theirs: a package
	 * supplies a default, it does not overrule a choice.
	 *
	 * @param array<string, mixed> $settings The settings, references already resolved to ids.
	 *
	 * @return void
	 */
	private function applySettings(array $settings): void {
		$present = $this->appConfig->getKeys(Application::APP_ID);
		foreach ($settings as $key => $value) {
			if (in_array((string)$key, $present, true) === true) {
				continue;
			}

			$this->appConfig->setValueString(Application::APP_ID, (string)$key, $this->settingValue(value: $value));
		}
	}//end applySettings()

	/**
	 * A scalar setting as the string the app config stores.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The stored form.
	 */
	private function settingValue(mixed $value): string {
		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		return (string)$value;
	}//end settingValue()

	/**
	 * One outcome, in the shape every caller reads.
	 *
	 * @param string $status  One of the status constants.
	 * @param string $id      The package id.
	 * @param string $version The package version.
	 * @param string $message What happened, for a person.
	 *
	 * @return array{status: string, package: string, version: string, message: string}
	 */
	private function outcome(string $status, string $id, string $version, string $message): array {
		return ['status' => $status, 'package' => $id, 'version' => $version, 'message' => $message];
	}//end outcome()

	/**
	 * The outcome of an install that failed.
	 *
	 * @param PackageInstallRun $run     The install.
	 * @param string            $message What happened, for a person.
	 *
	 * @return array{status: string, package: string, version: string, message: string}
	 */
	private function failed(PackageInstallRun $run, string $message): array {
		return $this->outcome(status: self::FAILED, id: $run->id, version: $run->version, message: $message);
	}//end failed()
}//end class
