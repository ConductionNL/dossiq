<?php

/**
 * The apps dossiq needs and the apps it works with, as the integrations tab lists them.
 *
 * @category Settings
 * @package  OCA\Dossiq\Settings
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

namespace OCA\Dossiq\Settings;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;

/**
 * Names each sibling app by its product name and says under which app id it was found.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class DependencyApps {

	/**
	 * Product name by canonical app id, required apps first.
	 *
	 * @var array<string, array{name: string, required: bool}>
	 */
	public const APPS = [
		'openregister' => ['name' => 'Openregister', 'required' => true],
		'integriq'     => ['name' => 'Integriq', 'required' => true],
		'filinq'       => ['name' => 'Filinq', 'required' => false],
		'pipelinq'     => ['name' => 'Pipelinq', 'required' => false],
		'portaliq'     => ['name' => 'Portaliq', 'required' => false],
		'humaniq'      => ['name' => 'Humaniq', 'required' => false],
	];

	/**
	 * One row per app: its name, the id it was found under (else the canonical id) and whether it is here.
	 *
	 * @param IAppManager $appManager Whether an app is installed.
	 *
	 * @return array<int, array{name: string, appId: string, required: bool, present: bool}> The rows.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function read(IAppManager $appManager): array {
		$rows = [];
		foreach (self::APPS as $canonical => $app) {
			$found  = FleetAppId::resolve(appManager: $appManager, canonical: $canonical);
			$rows[] = [
				'name'     => $app['name'],
				'appId'    => ($found ?? $canonical),
				'required' => $app['required'],
				'present'  => $found !== null,
			];
		}

		return $rows;
	}//end read()

}//end class
