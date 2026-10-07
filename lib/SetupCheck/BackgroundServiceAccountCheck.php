<?php

/**
 * Dossiq BackgroundServiceAccountCheck.
 *
 * Tells the admin, in the administration overview, when the background jobs
 * have no usable service account. Until one is picked the termijn reminder
 * sweep sends nothing and saves nothing.
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\SetupCheck;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccount;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns while the background service account is unset, unknown, disabled or outside its group.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class BackgroundServiceAccountCheck implements ISetupCheck {

	/**
	 * Constructor.
	 *
	 * @param BackgroundServiceAccount $serviceAccount The account to check.
	 * @param IL10N                    $l10n           The localisation service.
	 */
	public function __construct(
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The name in the administration overview.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Dossiq background service account');
	}//end getName()

	/**
	 * The category in the administration overview.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
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
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function run(): SetupResult {
		$status = $this->serviceAccount->status();
		if ($status['usable'] === true) {
			return SetupResult::success(
				$this->l10n->t('The Dossiq background jobs save as %s.', [$status['userId']])
			);
		}

		$why = match ($status['reason']) {
			ServiceAccount::REASON_UNKNOWN => $this->l10n->t('The chosen account does not exist.'),
			ServiceAccount::REASON_DISABLED => $this->l10n->t('The chosen account is disabled.'),
			ServiceAccount::REASON_NOT_IN_GROUP => $this->l10n->t(
				'The chosen account is not in the group %s.',
				[$this->serviceAccount->group()]
			),
			default => $this->l10n->t('No account is chosen.'),
		};

		return SetupResult::warning(
			$why.' '.$this->l10n->t(
				'Until you choose one in the Dossiq settings, reminders on suspended terms are not sent and nothing is saved.'
			)
		);
	}//end run()
}//end class
