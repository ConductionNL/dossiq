<?php

/**
 * The example rows of packages installed before example data was chosen.
 *
 * A package installed on an instance that had not opted into example data
 * skipped its example rows, and installing it again writes nothing because it
 * is current. When the operator loads example data later, this writes those
 * example rows and records them under their package; nothing else of the
 * package is touched.
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
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Writes the example rows of the packages that are current here.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
class InstalledPackageExamples {
	use StampsPackageRows;

	/**
	 * Constructor.
	 *
	 * @param PackageDirectory            $packages   Reads the packages from disk.
	 * @param PackageValidator            $validator  Says what is wrong with a package.
	 * @param PackageWriter               $writer     Writes all rows or none.
	 * @param ShippedConfigurationService $ledger     Records what was shipped.
	 * @param IAppConfig                  $appConfig  Holds the example data decision.
	 * @param LoggerInterface             $logger     What a write says about itself.
	 * @param PackageSchemaReferences     $references Resolves the schema an example file names.
	 */
	public function __construct(
		private readonly PackageDirectory $packages,
		private readonly PackageValidator $validator,
		private readonly PackageWriter $writer,
		private readonly ShippedConfigurationService $ledger,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly PackageSchemaReferences $references,
	) {
	}//end __construct()

	/**
	 * Write the example rows of every package installed here, in id order.
	 *
	 * @return array<int, array<string, mixed>> One outcome per installed package.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function forInstalled(): array {
		$installed = array_values(
			array_filter($this->packages->ids(), fn (string $id): bool => $this->ledger->installedVersion(set: $id) !== '')
		);

		return array_map(fn (string $id): array => $this->forPackage(id: $id), $installed);
	}//end forInstalled()

	/**
	 * Write the example rows of one package that is current here.
	 *
	 * A package installed as other content than it ships now is left alone:
	 * its next install writes everything it ships, its examples included.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> status, package, version and message; written,
	 *         adopted and skipped counts when example rows were written.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function forPackage(string $id): array {
		$package = $this->packages->read(id: $id);
		if ($package === null) {
			return $this->outcome(status: PackageInstaller::REFUSED, id: $id, version: '', message: "No package named '{$id}' is shipped.");
		}

		$validation = $this->validator->validateShipped(package: $package);
		$run = new PackageInstallRun(id: $id, manifest: (array)($validation['manifest'] ?? []), package: $package, overLocalChanges: false);
		if ($validation['valid'] === false) {
			return $this->outcome(status: PackageInstaller::REFUSED, id: $id, version: $run->version, message: "Package '{$id}' is not valid.");
		}

		if ($this->ledger->isPackageCurrent(set: $id, version: $run->version, digest: $run->digest) === false) {
			return $this->outcome(
				status: PackageInstaller::REFUSED,
				id: $id,
				version: $run->version,
				message: "Package '{$id}' {$run->version} is not what is installed here; its next install writes its examples.",
			);
		}

		$examples = new PackageExamples(appConfig: $this->appConfig, logger: $this->logger);
		$groups = array_values(
			array_filter(
				$examples->groups(run: $run, packages: $this->packages, references: $this->references),
				static fn (array $group): bool => $group['rows'] !== []
			)
		);
		if ($groups === []) {
			return $this->outcome(
				status: PackageInstaller::CURRENT,
				id: $id,
				version: $run->version,
				message: "Package '{$id}' has no example rows to write.",
			);
		}

		return $this->write(run: $run, groups: $groups);
	}//end forPackage()

	/**
	 * Write a current package's example groups and record them under it.
	 *
	 * @param PackageInstallRun                $run    The install.
	 * @param array<int, array<string, mixed>> $groups The example groups, none empty.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function write(PackageInstallRun $run, array $groups): array {
		$missing = array_column($groups, 'missing');
		if ($missing !== []) {
			return $this->outcome(
				status: PackageInstaller::REFUSED,
				id: $run->id,
				version: $run->version,
				message: "The examples of package '{$run->id}' need " . implode('; ', array_column($groups, 'reason')) . '.',
			) + ['missing' => array_values(array_unique($missing))];
		}

		$result = $this->writer->writePackage(package: $run->id, groups: $groups);
		if ($result['status'] !== 'success') {
			return $this->outcome(status: PackageInstaller::FAILED, id: $run->id, version: $run->version, message: $result['message']);
		}

		if ($this->recordRows(run: $run, written: $result['written'], adopted: $result['adopted']) === false) {
			$this->writer->undo(written: $result['written']);

			return $this->outcome(
				status: PackageInstaller::FAILED,
				id: $run->id,
				version: $run->version,
				message: "The examples of package '{$run->id}' were not written: a row could not be recorded in the shipped-set ledger,"
					. ' so every row was taken back.',
			);
		}

		$counts = ['written' => count($result['written']), 'adopted' => count($result['adopted']), 'skipped' => count($result['skipped'])];
		$this->logger->info(
			'Wrote the examples of package {id} {version}: {written} row(s) written, {adopted} adopted, {skipped} already present',
			['id' => $run->id, 'version' => $run->version] + $counts
		);

		return $this->outcome(status: PackageInstaller::INSTALLED, id: $run->id, version: $run->version, message: $result['message']) + $counts;
	}//end write()

	/**
	 * One outcome, in the shape every package caller reads.
	 *
	 * @param string $status  One of PackageInstaller's status constants.
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
