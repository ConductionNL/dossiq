<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCP\AppFramework\Services\IInitialState;

/**
 * Hands the page the uuid of each shipped flow a manifest action names.
 *
 * A `run-node` action needs a `flowId`, and a shipped flow's uuid is minted
 * per instance when OpenRegister imports the declaration, so the manifest
 * cannot carry it. It carries `@resolve:<key>` instead, and nextcloud-vue
 * resolves that from this app's initial state under `<key>`. This class
 * fills those slots: one initial-state key per shipped flow, read from the
 * flow store by the flow's declared name.
 *
 * A flow that is not stored (no OpenRegister, or the import has not run)
 * leaves its slot unset; the library then reports the sentinel unresolved
 * and the action does not run, which is the honest answer.
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
 */
class ShippedFlowIds {

	/**
	 * Initial-state key => the shipped flow's declared name.
	 *
	 * Add a row when a manifest action names a new shipped flow by
	 * `@resolve:<key>`; the declaration lives in lib/Settings.
	 *
	 * @var array<string, string>
	 */
	public const KEYS = [
		'generate_document_flow' => 'Generate a document',
	];

	/**
	 * Constructor.
	 *
	 * @param ShippedFlowAdoption $adoption Reads what the shipped flows look like.
	 */
	public function __construct(
		private readonly ShippedFlowAdoption $adoption,
	) {
	}//end __construct()

	/**
	 * The uuid per key, for every key whose flow is stored.
	 *
	 * @return array<string, string> Initial-state key => flow uuid.
	 *
	 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
	 */
	public function resolve(): array {
		$census = $this->adoption->census();
		if (($census['available'] ?? false) !== true) {
			return [];
		}

		$byName = [];
		foreach ((array)($census['flows'] ?? []) as $flow) {
			$name = (string)($flow['name'] ?? '');
			$uuid = (string)($flow['uuid'] ?? '');
			if ($name !== '' && $uuid !== '' && isset($byName[$name]) === false) {
				$byName[$name] = $uuid;
			}
		}

		$ids = [];
		foreach (self::KEYS as $key => $name) {
			if (isset($byName[$name]) === true) {
				$ids[$key] = $byName[$name];
			}
		}

		return $ids;
	}//end resolve()

	/**
	 * Put each stored flow's uuid into the page's initial state.
	 *
	 * @param IInitialState $initialState The page's initial state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
	 */
	public function provide(IInitialState $initialState): void {
		foreach ($this->resolve() as $key => $uuid) {
			$initialState->provideInitialState($key, $uuid);
		}
	}//end provide()
}//end class
