<?php
/**
 * Register Storage Declaration
 *
 * Says, for every schema of the dossiq register, that its objects live in a
 * magic table — because OpenRegister only believes a register that says so.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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

namespace OCA\Dossiq\Service\Settings;

/**
 * Declares magic-table storage for every schema of the register.
 *
 * OpenRegister reads where a schema's objects live from
 * `configuration.schemas.<slug>.magicMapping` on the register
 * (openregister/docs/development-notes/MAGIC-MAPPER-CONFIGURATION.md).
 * Dossiq's objects have lived in magic tables all along — OpenRegister's blob
 * migration moved every object there — but the register never said so, and
 * OpenRegister's object-name resolver searches only the tables of schemas
 * that are declared. The visible symptom was a sidebar filter over a `$ref`
 * property listing uuids where every other register lists names.
 *
 * Derived from the register's schema list rather than written out per schema:
 * the register.d fragments add schemas the monolith never sees, and a
 * hand-kept list of eighty drifts the first time one is forgotten. A schema
 * the monolith or a fragment declares explicitly is left as declared.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class RegisterStorageDeclaration {


	/**
	 * Add the storage declaration to the effective register configuration.
	 *
	 * @param array<string, mixed> $config       The merged register configuration about to be imported.
	 * @param string               $registerSlug The register to declare for.
	 *
	 * @return array<string, mixed> The configuration, with every schema declared.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function declare(array $config, string $registerSlug = 'dossiq'): array {
		$register = $config['components']['registers'][$registerSlug] ?? null;
		if (is_array($register) === false) {
			return $config;
		}

		$schemas = $register['schemas'] ?? [];
		if (is_array($schemas) === false) {
			return $config;
		}

		$configuration = $register['configuration'] ?? [];
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$declared = $configuration['schemas'] ?? [];
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach ($schemas as $slug) {
			if (is_string($slug) === false || $slug === '' || isset($declared[$slug]) === true) {
				continue;
			}

			$declared[$slug] = [
				'magicMapping'    => true,
				'autoCreateTable' => true,
			];
		}

		$configuration['schemas'] = $declared;

		// The import REPLACES the stored configuration (Register::hydrate sets
		// every key it is handed), so the flag MigrateArchivalToOpenRegister
		// switches on at runtime has to be declared here too, or a re-import
		// between upgrades switches it off.
		if (isset($configuration['tmloEnabled']) === false) {
			$configuration['tmloEnabled'] = true;
		}

		$config['components']['registers'][$registerSlug]['configuration'] = $configuration;

		return $config;
	}//end declare()
}//end class
