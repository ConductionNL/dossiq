<?php

/**
 * Dossiq repair-step once-only run helper.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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

namespace OCA\Dossiq\Repair\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\Migration\IOutput;

/**
 * The opening of a repair step that runs once over one schema: skip when its
 * `DONE_KEY` already holds `DONE_VERSION`, say so and stop when OpenRegister
 * or the schema is unavailable, else run the work under the system identity.
 *
 * The using class has `$appConfig` (IAppConfig), `$settingsService`
 * (SettingsServiceInterface), the `DONE_KEY`, `DONE_VERSION`,
 * `TARGET_SCHEMA_KEY` and `UNAVAILABLE_NOTE` constants, and composes
 * {@see RunsUnderSystemIdentity}.
 *
 * @spec exclude shared opening of the repair steps, covered by their own specs
 */
trait RunsOnce {

	/**
	 * Run `$work` once on the configured target schema.
	 *
	 * @param IOutput                       $output The upgrade output.
	 * @param callable(RepairSchema): mixed $work   The step's work on its schema.
	 *
	 * @return void
	 */
	private function onceOnSchema(IOutput $output, callable $work): void {
		if ($this->alreadyDone() === true) {
			return;
		}

		$target = (new RepairSchemas(settingsService: $this->settingsService))->configured(schemaKey: self::TARGET_SCHEMA_KEY);
		if ($target === null) {
			$output->info(self::UNAVAILABLE_NOTE);
			return;
		}

		$this->withSystemIdentity(
			objectService: $target->objectService,
			work: function () use ($work, $target): void {
				$work($target);
			}
		);
	}//end onceOnSchema()

	/**
	 * Whether this step already ran at its current version.
	 *
	 * @return bool
	 */
	private function alreadyDone(): bool {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION;
	}//end alreadyDone()
}//end trait
