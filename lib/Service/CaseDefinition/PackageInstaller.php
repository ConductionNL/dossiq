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
	 */
	public function __construct(
		private readonly PackageDirectory $packages,
		private readonly PackageValidator $validator,
		private readonly PackageCapabilities $capabilities,
		private readonly PackageWriter $writer,
		private readonly ShippedConfigurationService $ledger,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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
		$outcomes = [];
		foreach ($this->catalogue() as $entry) {
			if ($entry['install'] !== 'auto') {
				continue;
			}

			$outcomes[] = $this->install(id: $entry['id']);
		}

		return $outcomes;
	}//end installAutomatic()

	/**
	 * Install one package.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> status, package, version and message; errors
	 *         when refused as invalid, missing when a capability is missing,
	 *         written and skipped counts when installed.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function install(string $id): array {
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

		$missing = $this->capabilities->missing(requires: $this->requirements(manifest: $manifest));
		if ($missing !== []) {
			return $this->outcome(
				status: self::REFUSED,
				id: $id,
				version: $version,
				message: "Package '{$id}' needs " . implode(', ', $missing) . ', which this instance does not provide.',
			) + ['missing' => $missing];
		}

		return $this->write(id: $id, version: $version, manifest: $manifest, files: $package['files']);
	}//end install()

	/**
	 * Write a valid package whose requirements are met, and record it.
	 *
	 * @param string                $id       The package id.
	 * @param string                $version  The package version.
	 * @param array<string, mixed>  $manifest The manifest.
	 * @param array<string, string> $files    The package files.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function write(string $id, string $version, array $manifest, array $files): array {
		if ($this->ledger->ledgerAvailable() === false) {
			return $this->outcome(
				status: self::FAILED,
				id: $id,
				version: $version,
				message: "Package '{$id}' was not installed: the shipped-set ledger is not configured, so its rows could not be recorded.",
			);
		}

		if ($this->ledger->installedVersion(set: $id) === $version) {
			return $this->outcome(status: self::CURRENT, id: $id, version: $version, message: "Package '{$id}' {$version} is already installed.");
		}

		$parts = (array)($manifest['parts'] ?? []);
		$result = $this->writer->writePackage(package: $id, groups: $this->groups(parts: $parts, files: $files));
		if ($result['status'] !== 'success') {
			return $this->outcome(status: self::FAILED, id: $id, version: $version, message: $result['message']);
		}

		$recorded = $this->record(id: $id, version: $version, written: $result['written'])
			&& $this->record(id: $id, version: $version, written: $this->notYetInThisPackage(id: $id, adopted: $result['adopted']));
		if ($recorded === false) {
			$this->writer->undo(written: $result['written']);

			return $this->outcome(
				status: self::FAILED,
				id: $id,
				version: $version,
				message: "Package '{$id}' was not installed: a row could not be recorded in the shipped-set ledger, so every row was taken back.",
			);
		}

		if (in_array('settings', $parts, true) === true) {
			$this->applySettings(files: $files);
		}

		$this->logger->info(
			'Installed package {id} {version}: {written} row(s) written, {adopted} adopted, {skipped} already present',
			[
				'id' => $id,
				'version' => $version,
				'written' => count($result['written']),
				'adopted' => count($result['adopted']),
				'skipped' => count($result['skipped']),
			]
		);

		return $this->outcome(status: self::INSTALLED, id: $id, version: $version, message: $result['message'])
			+ ['written' => count($result['written']), 'adopted' => count($result['adopted']), 'skipped' => count($result['skipped'])];
	}//end write()

	/**
	 * Everything the package requires: what it names, and what its parts imply.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return array<int, string> The requirements.
	 */
	private function requirements(array $manifest): array {
		$requires = array_map('strval', (array)($manifest['requires'] ?? []));
		foreach ((array)($manifest['parts'] ?? []) as $part) {
			$requires[] = (ShippedPackageRules::PART_CAPABILITY[(string)$part] ?? (string)$part);
		}

		return array_values(array_unique($requires));
	}//end requirements()

	/**
	 * The package's rows, grouped by the settings key of the schema they go to.
	 *
	 * Order matters for references: case type rows first, then flows that
	 * name the case type, then seed rows, each seed file in name order.
	 *
	 * @param array<int, mixed>     $parts The declared parts.
	 * @param array<string, string> $files The package files.
	 *
	 * @return array<int, array{schemaKey: string, rows: array<int, array<string, mixed>>}> The groups.
	 */
	private function groups(array $parts, array $files): array {
		$groups = [];
		if (in_array('case-type', $parts, true) === true) {
			$caseType = (array)json_decode($files['case-type.json'], true);
			foreach (self::CASE_TYPE_SCHEMAS as $collection => $schemaKey) {
				$rows = ($caseType[$collection] ?? []);
				if (is_array($rows) === true && $rows !== [] && array_is_list($rows) === false) {
					$rows = [$rows];
				}

				$groups[] = ['schemaKey' => $schemaKey, 'rows' => array_values((array)$rows)];
			}
		}

		if (in_array('flows', $parts, true) === true) {
			$groups[] = ['schemaKey' => 'workflow_template_schema', 'rows' => $this->rowsUnder(files: $files, dir: 'flows/')];
		}

		if (in_array('seed', $parts, true) === true) {
			foreach ($this->rowsUnder(files: $files, dir: 'seed/') as $seed) {
				$groups[] = ['schemaKey' => (string)$seed['schema'], 'rows' => array_values((array)$seed['rows'])];
			}
		}

		return array_values(array_filter($groups, static fn (array $group): bool => $group['rows'] !== []));
	}//end groups()

	/**
	 * The decoded .json files under one directory, in name order.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $dir   The directory prefix.
	 *
	 * @return array<int, array<string, mixed>> The decoded files.
	 */
	private function rowsUnder(array $files, string $dir): array {
		$rows = [];
		foreach ($files as $path => $content) {
			if (str_starts_with($path, $dir) === true && str_ends_with($path, '.json') === true) {
				$rows[] = (array)json_decode($content, true);
			}
		}

		return $rows;
	}//end rowsUnder()

	/**
	 * Record every written row in the shipped-set ledger, under the package id.
	 *
	 * @param string                           $id      The package id, which is the set name.
	 * @param string                           $version The package version.
	 * @param array<int, array<string, mixed>> $written The rows written.
	 *
	 * @return boolean True when every row was recorded.
	 */
	private function record(string $id, string $version, array $written): bool {
		foreach ($written as $row) {
			$stamped = $this->ledger->stamp(
				set: $id,
				targetSchema: (string)$row['schema'],
				targetObject: (string)$row['id'],
				object: (array)$row['row'],
				version: $version,
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
	 * @param array<string, string> $files The package files.
	 *
	 * @return void
	 */
	private function applySettings(array $files): void {
		$settings = (array)json_decode($files['settings.json'], true);
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
}//end class
