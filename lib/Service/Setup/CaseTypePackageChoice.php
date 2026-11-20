<?php

/**
 * Dossiq case type package choice.
 *
 * The setup wizard's Case types step: the case definition packages an
 * administrator may switch on, one card each, and the install a card's Load
 * button asks for. Packages that install on every instance (`install: auto`)
 * are already there and are not offered; only the on-request ones are.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Setup
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
 * @spec openspec/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Setup;

use OCA\Dossiq\Kernel\Contracts\CaseTypePackageChoiceInterface;
use OCA\Dossiq\Service\CaseDefinition\InstalledPackageExamples;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCP\IAppConfig;

/**
 * Offers and installs the on-request case definition packages.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class CaseTypePackageChoice implements CaseTypePackageChoiceInterface {

	/**
	 * App-config key the step's choice is stored under.
	 *
	 * Records a decision: an installed package and "none for now" both set it.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'case_type_package';

	/**
	 * The option that installs nothing.
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param PackageInstaller         $packages  The case definition package installer.
	 * @param IAppConfig               $appConfig App config, for the stored choice.
	 * @param InstalledPackageExamples $examples  Writes the examples of the packages installed here.
	 */
	public function __construct(
		private readonly PackageInstaller $packages,
		private readonly IAppConfig $appConfig,
		private readonly InstalledPackageExamples $examples,
	) {
	}//end __construct()

	/**
	 * The cards the step offers: none first, then every valid on-request package.
	 *
	 * @return array<int, array{id: string, label: string, description: string, icon: string}> The options.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function options(): array {
		$options = [
			[
				'id' => self::NONE,
				'label' => 'None for now',
				'description' => 'You can switch case types on later under Settings, Case types.',
				'icon' => 'CloseCircleOutline',
			],
		];

		foreach ($this->offered() as $entry) {
			$description = 'Not installed yet';
			if ($entry['installed'] !== '') {
				$description = 'Installed';
			}

			$options[] = [
				'id' => $entry['id'],
				'label' => $entry['title'],
				'description' => $description,
				'icon' => 'FolderCogOutline',
			];
		}

		return $options;
	}//end options()

	/**
	 * The packages the marketplace lists: every valid package with a summary.
	 *
	 * @return array<int, array{id: string, title: string, version: string, summary: string, installed: string, onRequest: bool}> The packages.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md#requirement-the-marketplace-lists-the-shipped-packages
	 */
	public function shipped(): array {
		$shipped = [];
		foreach ($this->packages->catalogue() as $entry) {
			if ($entry['valid'] !== true || ($entry['summary'] ?? '') === '') {
				continue;
			}

			$shipped[] = [
				'id' => (string)$entry['id'],
				'title' => (string)$entry['title'],
				'version' => (string)$entry['version'],
				'summary' => (string)$entry['summary'],
				'installed' => (string)($entry['installed'] ?? ''),
				'onRequest' => $entry['install'] === 'on-request',
			];
		}

		return $shipped;
	}//end shipped()

	/**
	 * Whether a package can be installed on request.
	 *
	 * @param string $id The package id.
	 *
	 * @return bool True for a valid on-request package.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md#requirement-an-administrator-installs-a-shipped-package
	 */
	public function isOffered(string $id): bool {
		return in_array($id, array_column($this->offered(), 'id'), true);
	}//end isOffered()

	/**
	 * Whether the step has been dealt with: a choice is stored, or an
	 * on-request package is already installed.
	 *
	 * @return bool True when the step is done.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function isDecided(): bool {
		if ($this->appConfig->getValueString('dossiq', self::CONFIG_KEY, '') !== '') {
			return true;
		}

		foreach ($this->offered() as $entry) {
			if ($entry['installed'] !== '') {
				return true;
			}
		}

		return false;
	}//end isDecided()

	/**
	 * Install the package a card names and record the choice.
	 *
	 * Installed and already-current both count. A refusal or a failed write
	 * stores nothing, so the step stays open.
	 *
	 * @param string $id The package id the card posted.
	 *
	 * @return array{success: bool, message: string, detail?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function install(string $id): array {
		$known = array_column($this->offered(), 'id');
		if (in_array($id, $known, true) === false) {
			return ['success' => false, 'message' => 'No case type package is called "' . $id . '".'];
		}

		$outcome = $this->packages->install(id: $id);
		$done = in_array($outcome['status'] ?? '', [PackageInstaller::INSTALLED, PackageInstaller::CURRENT], true);
		if ($done === false) {
			return ['success' => false, 'message' => (string)($outcome['message'] ?? ''), 'detail' => $outcome];
		}

		$this->appConfig->setValueString('dossiq', self::CONFIG_KEY, $id);

		return ['success' => true, 'message' => (string)($outcome['message'] ?? ''), 'detail' => $outcome];
	}//end install()

	/**
	 * Write the example rows of the packages installed before example data
	 * was chosen.
	 *
	 * Called once the example data decision is recorded. The dataset itself
	 * is in by then, so a failure here is answered as an outcome beside it
	 * rather than thrown.
	 *
	 * @return array<int, array<string, mixed>> One outcome per installed package.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function writeExamplesOfInstalled(): array {
		try {
			return $this->examples->forInstalled();
		} catch (\Throwable $e) {
			return [['status' => PackageInstaller::FAILED, 'package' => '', 'version' => '', 'message' => $e->getMessage()]];
		}
	}//end writeExamplesOfInstalled()

	/**
	 * The valid on-request packages in the catalogue.
	 *
	 * @return array<int, array{id: string, title: string, installed: string}> The packages.
	 */
	private function offered(): array {
		$offered = [];
		foreach ($this->packages->catalogue() as $entry) {
			if ($entry['install'] !== 'on-request' || $entry['valid'] !== true) {
				continue;
			}

			$offered[] = [
				'id' => (string)$entry['id'],
				'title' => (string)$entry['title'],
				'installed' => (string)($entry['installed'] ?? ''),
			];
		}

		return $offered;
	}//end offered()
}//end class
