<?php

/**
 * Dossiq repair-step once-only run helper over OpenRegister's object service.
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
 * The opening of a repair step that runs once with the object service: skip
 * when its `DONE_KEY` already holds `DONE_VERSION`, say so and stop when
 * OpenRegister is unavailable, else run the work under the system identity.
 *
 * The using class has `$appConfig` (IAppConfig), `$settingsService`, the
 * `DONE_KEY`, `DONE_VERSION` and `UNAVAILABLE_NOTE` constants, and composes
 * {@see RunsUnderSystemIdentity}.
 *
 * @spec exclude shared opening of the repair steps, covered by their own specs
 */
trait RunsOnceWithObjectService {

	/**
	 * Run `$work` once with OpenRegister's object service.
	 *
	 * @param IOutput                 $output The upgrade output.
	 * @param callable(object): mixed $work   The step's work on the object service.
	 *
	 * @return void
	 */
	private function onceWithObjectService(IOutput $output, callable $work): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info(self::UNAVAILABLE_NOTE);
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($work, $objectService): void {
				$work($objectService);
			}
		);
	}//end onceWithObjectService()
}//end trait
