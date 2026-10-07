<?php

/**
 * Dossiq DsoIntakeCheck.
 *
 * Tells the admin, in the administration overview, when no DSO verzoek
 * becomes a case: `dso_vergunningaanvraag_schema` is empty, because integriq
 * is not installed or because an admin turned DSO intake off.
 *
 * @category SetupCheck
 * @package  OCA\Dossiq\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\SetupCheck;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\DefaultDsoIntakeSchema;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns while DSO intake is off.
 *
 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
 */
class DsoIntakeCheck implements ISetupCheck {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig  $appConfig  The app config.
	 * @param IAppManager $appManager The app manager.
	 * @param IL10N       $l10n       The localisation service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The name in the administration overview.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Dossiq DSO intake');
	}//end getName()

	/**
	 * The category in the administration overview.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * Run the check.
	 *
	 * @return SetupResult The result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the only way OCP offers to build one.
	 *
	 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
	 */
	public function run(): SetupResult {
		$schema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: DefaultDsoIntakeSchema::KEY,
			default: ''
		);

		if ($schema !== '') {
			return SetupResult::success(
				$this->l10n->t('A DSO verzoek becomes a case. DSO intake reads schema %s.', [$schema])
			);
		}

		$off = $this->l10n->t('DSO intake is off, so no DSO verzoek becomes a case.');
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			return SetupResult::warning(
				$off.' '.$this->l10n->t('integriq is not installed. Install integriq and upgrade Dossiq to turn it on.')
			);
		}

		return SetupResult::warning(
			$off.' '.$this->l10n->t(
				'No DSO intake schema is set. Set dso_vergunningaanvraag_schema to the id of integriq\'s dso_verzoek schema to turn it on.'
			)
		);
	}//end run()
}//end class
