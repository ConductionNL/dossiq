<?php

/**
 * Dossiq installed package admin tabs.
 *
 * The admin settings page draws one tab per installed package that declares
 * one ({@see PackageAdminTab}). This reads which packages those are and what
 * each installed, from the shipped-set ledger: every row an install wrote is
 * recorded there under the package id, so the ledger, not the package files,
 * says what is on this instance. A package the ledger holds nothing for is not
 * installed here and gets no tab.
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
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * Lists the installed packages that declare an admin tab, with what each installed.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
class PackageAdminTabs {

	/**
	 * Constructor.
	 *
	 * @param PackageDirectory $packages Reads the packages from disk.
	 * @param StarterStore     $store    Reads the ledger and the rows it names.
	 * @param PackageAdminTab  $adminTab Reads a manifest's tab declaration.
	 * @param TranslatedText   $text     Reads a translatable title as text.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	public function __construct(
		private readonly PackageDirectory $packages,
		private readonly StarterStore $store,
		private readonly PackageAdminTab $adminTab = new PackageAdminTab(),
		private readonly TranslatedText $text = new TranslatedText(),
	) {
	}//end __construct()

	/**
	 * Every installed package with an admin tab, in package id order.
	 *
	 * Each entry carries the package id, its installed version, the tab's
	 * title and, per declared list, the items the package installed: their
	 * id, and the title and status the row holds here when this app stores it.
	 *
	 * @return array<int, array{id: string, version: string, title: string,
	 *         lists: array<string, array<int, array{id: string, title: string, status: string}>>}>
	 *         The tabs; none when the ledger cannot be read.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	public function installed(): array {
		$tabs = [];
		foreach ($this->packages->ids() as $id) {
			$tab = $this->declaredTab(id: $id);
			if ($tab === null) {
				continue;
			}

			$ledger = $this->store->rows(configKey: ShippedConfigurationService::LEDGER, filters: ['set' => $id]);
			$ledger = array_values(array_filter((array)$ledger, static fn ($row): bool => is_array($row) === true && ($row['set'] ?? '') === $id));
			if ($ledger === []) {
				continue;
			}

			$tabs[] = [
				'id' => $id,
				'version' => $this->installedVersion(ledger: $ledger),
				'title' => $tab['title'],
				'lists' => $this->lists(names: $tab['lists'], ledger: $ledger),
			];
		}

		return $tabs;
	}//end installed()

	/**
	 * The tab a package's manifest declares, or null.
	 *
	 * @param string $id The package id.
	 *
	 * @return array{title: string, lists: array<int, string>}|null The tab.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function declaredTab(string $id): ?array {
		$package = $this->packages->read(id: $id);
		$manifest = json_decode((string)($package['files'][PackageDirectory::MANIFEST] ?? ''), true);
		if (is_array($manifest) === false) {
			return null;
		}

		return $this->adminTab->parse(manifest: $manifest);
	}//end declaredTab()

	/**
	 * The highest version the ledger records for the package.
	 *
	 * @param array<int, array<string, mixed>> $ledger The package's ledger rows.
	 *
	 * @return string The version.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function installedVersion(array $ledger): string {
		$highest = '';
		foreach ($ledger as $row) {
			$version = (string)($row['setVersion'] ?? '');
			if ($highest === '' || version_compare($version, $highest, '>') === true) {
				$highest = $version;
			}
		}

		return $highest;
	}//end installedVersion()

	/**
	 * The items of each declared list, from the package's ledger rows.
	 *
	 * @param array<int, string>               $names  The declared lists.
	 * @param array<int, array<string, mixed>> $ledger The package's ledger rows.
	 *
	 * @return array<string, array<int, array{id: string, title: string, status: string}>> The items by list.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function lists(array $names, array $ledger): array {
		$slugs = $this->slugsBySchema();
		$lists = [];
		foreach ($names as $name) {
			$lists[$name] = [];
			foreach ($ledger as $row) {
				$schema = (string)($row['targetSchema'] ?? '');
				$slug = ($slugs[$schema] ?? $schema);
				$object = (string)($row['targetObject'] ?? '');
				if ($object !== '' && in_array($slug, PackageAdminTab::LISTS[$name], true) === true) {
					$lists[$name][] = $this->item(slug: $slug, id: $object);
				}
			}
		}

		return $lists;
	}//end lists()

	/**
	 * The slug of each list schema by the schema id it is configured as here,
	 * so a ledger row recorded under the id before slugs were reads as its slug.
	 *
	 * @return array<string, string> Slugs by schema id.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function slugsBySchema(): array {
		$slugs = [];
		foreach (PackageAdminTab::LISTS as $listSlugs) {
			foreach ($listSlugs as $slug) {
				$configKey = (SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? '');
				$schemaId = '';
				if ($configKey !== '') {
					$schemaId = $this->store->schemaIdOf(configKey: $configKey);
				}

				if ($schemaId !== '') {
					$slugs[$schemaId] = $slug;
				}
			}
		}

		return $slugs;
	}//end slugsBySchema()

	/**
	 * One listed item: its id, and its title and status when this app stores the row.
	 *
	 * @param string $slug The schema slug.
	 * @param string $id   The row id.
	 *
	 * @return array{id: string, title: string, status: string} The item.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function item(string $slug, string $id): array {
		$configKey = (SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? '');
		$row = null;
		if ($configKey !== '') {
			$row = $this->store->row(configKey: $configKey, id: $id);
		}

		$row = (array)$row;

		return [
			'id' => $id,
			'title' => $this->text->forReader(value: ($row['title'] ?? ($row['name'] ?? ''))),
			'status' => (string)($row['lifecycleStatus'] ?? ($row['status'] ?? '')),
		];
	}//end item()
}//end class
