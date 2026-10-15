<?php

/**
 * Dossiq package capabilities.
 *
 * What a case definition package may ask this instance for. A package names
 * the capabilities it needs in its manifest (`requires`), and the installer
 * refuses a package whose capability is missing, by name, before it writes a
 * single row. A procedure that installs without the code it runs on is a case
 * type that looks complete and does nothing at its first transition.
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCP\App\IAppManager;

/**
 * Says which capabilities a package asks for that this instance lacks.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageCapabilities {

	/**
	 * The prefix of a requirement on another app, as in `app:decidiq`.
	 */
	public const APP_PREFIX = 'app:';

	/**
	 * The capabilities this instance provides.
	 *
	 * 🔑 A CAPABILITY IS LISTED HERE ONLY WHEN ITS CODE IS ON THIS BRANCH. The
	 * catalogue in hydra ADR-118 names more (term, notice, decision-document);
	 * each is added to this list in the pull request that builds it, so a
	 * package that needs one is refused until the code exists, and installs
	 * on the release that ships it.
	 *
	 * @var array<int, string>
	 */
	public const PROVIDED = [
		// The parts the installer itself writes.
		'case-type',
		'flow',
		'seed',
		'settings',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Answers whether another app is installed.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The requirements this instance cannot meet, in the order the package names them.
	 *
	 * @param array<int, mixed> $requires The package's `requires` list.
	 *
	 * @return array<int, string> The missing capabilities; empty when every one is met.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function missing(array $requires): array {
		$missing = [];
		foreach ($requires as $requirement) {
			$requirement = trim((string)$requirement);
			if ($requirement === '' || $this->provides(requirement: $requirement) === true) {
				continue;
			}

			$missing[] = $requirement;
		}

		return $missing;
	}//end missing()

	/**
	 * Whether this instance meets one requirement.
	 *
	 * @param string $requirement A capability id, or `app:<app id>`.
	 *
	 * @return boolean True when it is met.
	 */
	private function provides(string $requirement): bool {
		if (str_starts_with($requirement, self::APP_PREFIX) === true) {
			$appId = substr($requirement, strlen(self::APP_PREFIX));

			return ($appId !== '' && $this->appManager->isInstalled($appId) === true);
		}

		return in_array($requirement, self::PROVIDED, true);
	}//end provides()
}//end class
