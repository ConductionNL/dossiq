<?php

/**
 * Dossiq MailServices.
 *
 * Resolves Nextcloud Mail's services and accounts for NextcloudMailGateway.
 * It names no `OCA\Mail` symbol itself: the gateway hands it every class name
 * (design D-1, one file names Mail), and it answers null wherever Mail is
 * absent, moved a service or refuses an account.
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
 * @spec openspec/specs/inbound-mail-filters/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mail's services and accounts, by name, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailServices {

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager     The app manager, to see whether Mail is there.
	 * @param ContainerInterface $container      The DI container, to resolve Mail's services by name.
	 * @param LoggerInterface    $logger         Logger.
	 * @param array{0: string, 1: string} $mailNames Mail's app id, and the class name of its account service.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly array $mailNames,
	) {
	}//end __construct()

	/**
	 * Whether Nextcloud Mail is installed and answering.
	 *
	 * @return boolean True when intake can read messages.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isAvailable(): bool {
		try {
			return $this->appManager->isInstalled($this->mailNames[0]);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: could not ask whether the Mail app is installed',
				['error' => $e->getMessage()]
			);
			return false;
		}
	}//end isAvailable()

	/**
	 * Resolve one of Mail's services by name.
	 *
	 * @param string $name The fully qualified class name.
	 *
	 * @return object|null The service, or null when Mail is absent or moved it.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function service(string $name): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a Nextcloud Mail service could not be resolved, so intake is unavailable',
				['service' => $name, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()

	/**
	 * Resolve one of Mail's services that offers the given method.
	 *
	 * @param string $name   The fully qualified class name.
	 * @param string $method The method the caller is about to use.
	 *
	 * @return object|null The service, or null when Mail is absent or the method is gone.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function serviceWith(string $name, string $method): ?object {
		$service = $this->service(name: $name);
		if ($service === null || method_exists($service, $method) === false) {
			return null;
		}

		return $service;
	}//end serviceWith()

	/**
	 * Resolve one Mail account entity.
	 *
	 * @param integer $accountId The account.
	 *
	 * @return object|null The account, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function account(int $accountId): ?object {
		if ($accountId <= 0) {
			return null;
		}

		$service = $this->serviceWith(name: $this->mailNames[1], method: 'findById');
		if ($service === null) {
			return null;
		}

		return $this->findAccount(service: $service, accountId: $accountId);
	}//end account()

	/**
	 * The account and the named Mail service a call needs, or null when either is missing.
	 *
	 * @param integer $accountId The account.
	 * @param string  $name      The service's fully qualified class name.
	 * @param string  $method    The method the caller is about to use.
	 *
	 * @return ThreadingLookup|null The account with the service, as one pair.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function accountWith(int $accountId, string $name, string $method): ?ThreadingLookup {
		$account = $this->account(accountId: $accountId);
		$manager = $this->service(name: $name);
		if ($account === null || $manager === null || method_exists($manager, $method) === false) {
			return null;
		}

		return new ThreadingLookup(account: $account, manager: $manager);
	}//end accountWith()

	/**
	 * Read one Mail account through Mail's account service.
	 *
	 * @param object  $service   Mail's account service.
	 * @param integer $accountId The account.
	 *
	 * @return object|null The account, or null when it cannot be read.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function findAccount(object $service, int $accountId): ?object {
		try {
			$account = $service->findById($accountId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the configured intake account could not be read',
				['account' => $accountId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($account) === false) {
			return null;
		}

		return $account;
	}//end findAccount()
}//end class
