<?php

/**
 * Saves one object to the schema a config key names
 *
 * Several services persist to "the register, and the schema named by this
 * config key", and answer with the payload itself when that schema is not
 * configured or the save fails. This writer is that one save; its owner says
 * how a failed save is logged.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Closure;
use OCA\Dossiq\Service\SettingsService;

/**
 * One save to a configured schema, with the owner's failure report.
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
final class ConfiguredSchemaWriter {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reads the object service, the register and the schema.
	 * @param Closure         $onFailure       Reports a save that threw; receives the Throwable.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly Closure $onFailure,
	) {
	}//end __construct()

	/**
	 * Save the object to the schema the config key names.
	 *
	 * @param string               $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $object          Payload.
	 *
	 * @return array<string, mixed> The saved object, or the payload when the
	 *                              save could not be performed.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function save(string $schemaConfigKey, array $object): array {
		$target = $this->target(schemaConfigKey: $schemaConfigKey);
		if ($target === null) {
			return $object;
		}

		return $this->store(target: $target, object: $object);
	}//end save()

	/**
	 * The object service, the register and the schema to save to, or null when one is missing.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null
	 */
	private function target(string $schemaConfigKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end target()

	/**
	 * Save the object to the target, reporting a save that threw.
	 *
	 * @param array{objectService: object, register: string, schema: string} $target Where to save.
	 * @param array<string, mixed>                                            $object Payload.
	 *
	 * @return array<string, mixed> The saved object, or the payload.
	 */
	private function store(array $target, array $object): array {
		try {
			$saved = $this->saveObjectAsArray(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				object: $object
			);
			return ($saved ?? $object);
		} catch (\Throwable $e) {
			($this->onFailure)($e);
			return $object;
		}
	}//end store()
}//end class
