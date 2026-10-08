<?php

/**
 * Dossiq OpenRegister bridge.
 *
 * OpenRegister is an optional runtime dependency, so none of its classes can
 * be type-hinted in a constructor: dossiq has to enable and run without it.
 * Every one of them is therefore reached by name through the container at
 * call time, and every one answers null when it cannot be reached. Callers
 * MUST handle that null, and the fail-closed reading is the right one: a
 * grant that cannot be asked about is NOT granted.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService}, which was over its
 * complexity ceiling. Holding the app's configuration and reaching another
 * app's services are two jobs, and the second is all of this class.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reaching OpenRegister's optional services by name.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class OpenRegisterBridge {

	/**
	 * The app whose presence every lookup here depends on.
	 *
	 * @var string
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Whether OpenRegister is installed and enabled.
	 * @param ContainerInterface $container  The DI container the classes are resolved through.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check if OpenRegister is installed and enabled.
	 *
	 * The isEnabledForUser() check resolves against the current user session
	 * and returns false in session-less contexts (occ commands, repair steps,
	 * background jobs) even when OpenRegister is enabled globally — which
	 * silently skipped the bezwaar/beroep seed during install/repair. Fall back
	 * to the session-less isInstalled() check so CLI/background callers see it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID) === true
			|| $this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === true;
	}//end isAvailable()

	/**
	 * Resolve the OpenRegister ObjectService from the DI container.
	 *
	 * Returns null when OpenRegister is not installed/enabled, or when the
	 * container cannot resolve the service (e.g. on a fresh install before
	 * configuration). Callers are expected to handle the null case.
	 *
	 * Mirrors the lazy-resolve pattern already used for ConfigurationService
	 * in loadConfiguration() — OpenRegister is an optional runtime dependency
	 * so we cannot type-hint the class directly in the constructor.
	 *
	 * @return object|null The OpenRegister ObjectService or null when unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function objectService(): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()

	/**
	 * Lazily resolve OpenRegister's per-object grant resolver.
	 *
	 * THE ONE THING DOSSIQ CANNOT ANSWER FOR ITSELF. A grant is a real
	 * Nextcloud share on the object's folder, resolved per request by
	 * OpenRegister, and since openregister#3873 that resolution walks the
	 * declared hierarchy: a grant on a parent case answers for its deelzaken.
	 * Asking this service is how dossiq consumes that instead of keeping a
	 * second, parallel answer, which is what ADR-022 is about and what
	 * `deelzaken-inherit-the-parent-grants` D-2 asks for by name.
	 *
	 * Same lazy-resolve contract as {@see self::objectService()}: an
	 * optional runtime dependency, resolved at call time rather than
	 * type-hinted, and callers MUST handle null. A null answer means dossiq
	 * cannot ask, which every caller here treats as NOT GRANTED — the
	 * fail-closed direction, and the behaviour dossiq had before inheritance
	 * existed at all.
	 *
	 * @return object|null OpenRegister's ObjectGrantResolver, or null when unavailable.
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function objectGrantResolver(): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\Rbac\ObjectGrantResolver');
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ObjectGrantResolver',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectGrantResolver()

	/**
	 * Lazily resolve OpenRegister's FileService for in-process file attachment.
	 *
	 * ADR-084 publishes `ObjectServiceInterface` — 25 methods — and **none of
	 * them attaches a file**. OpenRegister's own `files#create` route runs
	 * `FileService::addFile()`, and `FileService` is not a published contract,
	 * so an app that must attach bytes to an OpenRegister object in process has
	 * exactly this one route. Recorded as a contract gap in
	 * `openspec/changes/woo-publication-in-process-object-writes/proposal.md`
	 * rather than worked around with a self-addressed HTTP call, which is what
	 * ADR-080 D2/D3 forbids.
	 *
	 * Same lazy-resolve contract as {@see self::objectService()} and
	 * {@see self::approvalService()}: OpenRegister is an optional runtime
	 * dependency, so the class is resolved through the container at call time
	 * rather than type-hinted in the constructor, and callers MUST handle null.
	 *
	 * @return object|null The OpenRegister FileService or null when unavailable
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function fileService(): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister FileService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fileService()

	/**
	 * Lazily resolve OpenRegister's ApprovalService for parafering chain delegation.
	 *
	 * Per ADR-022 (apps consume OpenRegister abstractions) the parafering
	 * (sign-off routing) chain-state backend is OpenRegister's
	 * `approval-workflow` capability, exposed through
	 * `OCA\OpenRegister\Service\ApprovalService`. OpenRegister is an optional
	 * runtime dependency, so — exactly like getObjectService() — the class is
	 * resolved through the container at call time rather than type-hinted in the
	 * constructor. Callers MUST handle the null case (graceful degradation to
	 * the legacy in-array path during the migration window).
	 *
	 * @return object|null The OpenRegister ApprovalService or null when unavailable
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function approvalService(): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ApprovalService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ApprovalService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end approvalService()

	/**
	 * Lazily resolve an OpenRegister DI class by fully-qualified name.
	 *
	 * Generic helper for the parafering approval bridge to reach OpenRegister's
	 * ApprovalChainMapper / ApprovalStepMapper without a hard constructor
	 * dependency on the optional OpenRegister app.
	 *
	 * @param string $class Fully-qualified OpenRegister class name
	 *
	 * @return object|null The resolved service, or null when unavailable
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function classNamed(string $class): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister class',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end classNamed()
}//end class
