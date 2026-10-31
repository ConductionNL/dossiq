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

use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCP\IAppConfig;

/**
 * Offers and installs the on-request case definition packages.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class CaseTypePackageChoice {

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
	 * @param PackageInstaller $packages  The case definition package installer.
	 * @param IAppConfig       $appConfig App config, for the stored choice.
	 */
	public function __construct(
		private readonly PackageInstaller $packages,
		private readonly IAppConfig $appConfig,
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
