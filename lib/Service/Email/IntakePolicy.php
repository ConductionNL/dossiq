<?php

/**
 * Dossiq Intake Policy
 *
 * What a failing authentication verdict means, decided per case type rather
 * than centrally (design D-7).
 *
 * A melding openbare ruimte from an unauthenticated sender is a normal Tuesday.
 * A bezwaar from one is a forgery risk with a statutory consequence. One
 * instance-wide answer is therefore wrong for somebody whichever way it is set,
 * so the case type declares its own.
 *
 * 🔴 THE DEFAULT IS QUARANTINE, AND THAT IS A CHOICE ABOUT WHICH FAILURE HURTS
 * MORE. A zaaksysteem that silently drops a bezwaar misses a statutory term and
 * nobody finds out. One that accepts a forged bezwaar files a case that should
 * not exist, which is visible and reversible. Quarantine is the only answer
 * that is neither, so it is what a case type that declares nothing gets.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case type's answer to a failing verdict.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class IntakePolicy {

	use SearchesObjects;

	/**
	 * The message becomes a case, with the verdict recorded on it.
	 */
	public const ACCEPT = 'accept';

	/**
	 * A person decides. Nothing is created and nothing is deleted.
	 */
	public const QUARANTINE = 'quarantine';

	/**
	 * The message is refused and the sender is told.
	 */
	public const REFUSE = 'refuse';

	/**
	 * What a case type that declares nothing gets.
	 */
	public const DEFAULT_POLICY = self::QUARANTINE;

	/**
	 * The property a case type declares its policy in.
	 */
	public const PROPERTY = 'intakePolicy';

	/**
	 * The app-config key naming the group allowed to release a quarantine.
	 */
	public const INTAKE_ROLE_KEY = 'email_intake_role';

	/**
	 * Every policy.
	 *
	 * @var string[]
	 */
	public const ALL = [self::ACCEPT, self::QUARANTINE, self::REFUSE];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema resolution.
	 * @param IAppConfig      $appConfig       Instance configuration.
	 * @param IGroupManager   $groupManager    Group membership, for the intake role.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The policy one case type declares.
	 *
	 * A case type that cannot be read gets the default rather than an error.
	 * Intake runs on a cron and a case type that has been deleted must not stop
	 * the mailbox being emptied.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return string One of {@see self::ALL}.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function forCaseType(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return self::DEFAULT_POLICY;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return self::DEFAULT_POLICY;
		}

		try {
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: the case type behind an intake policy could not be read',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return self::DEFAULT_POLICY;
		}

		if ($caseType === null) {
			return self::DEFAULT_POLICY;
		}

		return $this->normalise(value: (string)($caseType[self::PROPERTY] ?? ''));
	}//end forCaseType()

	/**
	 * What to do with a message under one policy and one set of results.
	 *
	 * A verdict with no failure is accepted whatever the policy says, because
	 * the policy is about a FAILING verdict and nothing else. A case type that
	 * declares `refuse` does not refuse every message; it refuses the ones that
	 * failed a check.
	 *
	 * @param string                $policy  One of {@see self::ALL}.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return string The policy that applies: `accept`, `quarantine` or `refuse`.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function outcomeFor(string $policy, array $results): string {
		foreach ($results as $result) {
			if (AuthenticationResult::isFailure(value: $result) === true) {
				return $this->normalise(value: $policy);
			}
		}

		return self::ACCEPT;
	}//end outcomeFor()

	/**
	 * The group that runs intake, and may release a quarantined message.
	 *
	 * @return string The group id, or '' when none is named.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function intakeRole(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::INTAKE_ROLE_KEY, ''));
	}//end intakeRole()

	/**
	 * Whether a user may read the intake log and release a quarantine.
	 *
	 * 🔴 AN UNNAMED ROLE MEANS ADMINISTRATORS ONLY, NOT EVERYBODY. The intake
	 * log holds the original of every message the mailbox received, which is
	 * personal data about people who never consented to it being readable by
	 * the whole instance. An instance that has not configured a role has not
	 * said "anyone may read it".
	 *
	 * @param string $userId The user.
	 *
	 * @return boolean True when they may.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function mayRunIntake(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$role = $this->intakeRole();
		if ($role === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $role);
	}//end mayRunIntake()

	/**
	 * Coerce whatever a case type declared into a policy this app knows.
	 *
	 * @param string $value The declared value.
	 *
	 * @return string One of {@see self::ALL}.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function normalise(string $value): string {
		$policy = strtolower(trim($value));
		if (in_array($policy, self::ALL, true) === true) {
			return $policy;
		}

		return self::DEFAULT_POLICY;
	}//end normalise()
}//end class
