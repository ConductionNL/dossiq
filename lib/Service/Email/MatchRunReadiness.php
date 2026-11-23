<?php

/**
 * What an email match run needs before it starts, or why it cannot.
 *
 * The configured Mail account is the owner's own and the case number pattern
 * loads; the register and case schema are configured; OpenRegister is there
 * and can scope a read to the owner (`runAs()`); and OpenRegister's email leaf
 * is there to link with. The first that fails names the refusal code, and the
 * run is never started unscoped. {@see \OCA\Dossiq\Service\CaseEmailMatchService}
 * runs the batch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\CaseEmailMatchRun;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Prepares an email match run, or names why it is refused.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */
final class MatchRunReadiness {

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The register, the case schema and OpenRegister.
	 * @param MailMatchSources   $matching        The Mail account and the case number pattern.
	 * @param ContainerInterface $container       Resolves OpenRegister's email leaf.
	 * @param LoggerInterface    $logger          Logs a refusal that needs an administrator.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MailMatchSources $matching,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Everything one run needs, or the code for why it must not run.
	 *
	 * The order is the fail-closed order: the account and the configuration are
	 * checked before OpenRegister is touched at all, so an unconfigured register
	 * refuses with zero OpenRegister calls (REQ-ECM-007).
	 *
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId Their configured Mail account.
	 *
	 * @return CaseEmailMatchRun|string The run, or the refusal code.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function prepare(IUser $owner, int $accountId): CaseEmailMatchRun|string {
		$run = new CaseEmailMatchRun(owner: $owner, accountId: $accountId);
		$refusal = $this->mailboxRefusal(run: $run)
			?? $this->configurationRefusal(run: $run)
			?? $this->objectServiceRefusal(run: $run)
			?? $this->emailLeafRefusal(run: $run);

		return $refusal ?? $run;
	}//end prepare()

	/**
	 * Refuse a Mail account that is not the owner's, or an unusable pattern.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the pattern.
	 *
	 * @return string|null The refusal code, or null to go on.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function mailboxRefusal(CaseEmailMatchRun $run): ?string {
		if ($this->matching->messages->ownsAccount(accountId: $run->accountId, userId: $run->owner->getUID()) === false) {
			$this->logger->warning(
				'Dossiq: email case matching refused, the configured Mail account is not the user\'s own',
				['app' => AppIdentity::APP_ID, 'userId' => $run->owner->getUID(), 'accountId' => $run->accountId]
			);
			return 'account_not_owned';
		}

		$pattern = $this->matching->recognizer->loadPattern();
		if ($pattern === null) {
			return 'pattern_invalid';
		}

		$run->pattern = $pattern;

		return null;
	}//end mailboxRefusal()

	/**
	 * Refuse when the register or the case schema is not configured.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the register and schema.
	 *
	 * @return string|null The refusal code, or null to go on.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function configurationRefusal(CaseEmailMatchRun $run): ?string {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq: app-config "register" or "case_schema" is not configured; email case matching is refused, not run unscoped',
				['app' => AppIdentity::APP_ID]
			);
			return 'register_unconfigured';
		}

		$run->register = $register;
		$run->schema = $schema;

		return null;
	}//end configurationRefusal()

	/**
	 * Refuse when OpenRegister is missing or cannot scope a read to the owner.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the object service.
	 *
	 * @return string|null The refusal code, or null to go on.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function objectServiceRefusal(CaseEmailMatchRun $run): ?string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return 'openregister_unavailable';
		}

		if (method_exists($objectService, 'runAs') === false) {
			// Without runAs() the lookup would run with no subject, which a cron
			// process is: OpenRegister then scopes it to every organisation.
			$this->logger->warning(
				'Dossiq: OpenRegister has no runAs(); email case matching is refused rather than resolving cases outside the mailbox owner\'s scope',
				['app' => AppIdentity::APP_ID]
			);
			return 'scope_unavailable';
		}

		$run->objectService = $objectService;

		return null;
	}//end objectServiceRefusal()

	/**
	 * Refuse when OpenRegister's email leaf is not there to link with.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the leaf.
	 *
	 * @return string|null The refusal code, or null to go on.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function emailLeafRefusal(CaseEmailMatchRun $run): ?string {
		$linkService = $this->getEmailLinkService();
		if ($linkService === null || method_exists($linkService, 'linkEmail') === false) {
			return 'email_leaf_unavailable';
		}

		$run->linkService = $linkService;

		return null;
	}//end emailLeafRefusal()

	/**
	 * Resolve OpenRegister's email leaf, or null when it is not there.
	 *
	 * @return object|null The leaf service.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function getEmailLinkService(): ?object {
		try {
			$service = $this->container->get('OCA\OpenRegister\Service\EmailLinkService');
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end getEmailLinkService()
}//end class
