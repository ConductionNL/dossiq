<?php

/**
 * Dossiq active organisation profile.
 *
 * The name and logo of the organisation the administrator is working in,
 * as the setup wizard's Organisation step reads and writes them. They live on
 * OpenRegister's Organisation (`name`, `image`), which is the tenant
 * (tenancy-onto-openregister-organisation); dossiq keeps no copy.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Setup
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
 * @spec openspec/changes/setup-wizard-board-steps/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Setup;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the active organisation's name and logo.
 *
 * @spec openspec/changes/setup-wizard-board-steps/specs/first-time-setup/spec.md
 */
class ActiveOrganisationProfile {

	/**
	 * The wizard field that holds the organisation's name.
	 *
	 * @var string
	 */
	public const NAME_FIELD = 'organisation_name';

	/**
	 * The wizard field that holds the address of the organisation's logo.
	 *
	 * @var string
	 */
	public const LOGO_FIELD = 'organisation_logo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager App manager, for the OpenRegister availability check.
	 * @param ContainerInterface $container  DI container, resolves OpenRegister's organisation services.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The active organisation's name and logo.
	 *
	 * @return array{available: bool, name: string, logo: string} `available` is false when there is no active organisation to read.
	 *
	 * @spec openspec/changes/setup-wizard-board-steps/specs/first-time-setup/spec.md
	 */
	public function read(): array {
		$organisation = $this->active();
		if ($organisation === null) {
			return ['available' => false, 'name' => '', 'logo' => ''];
		}

		return [
			'available' => true,
			'name' => (string)($organisation->getName() ?? ''),
			'logo' => (string)($organisation->getImage() ?? ''),
		];
	}//end read()

	/**
	 * Write the fields the wizard posted onto the active organisation.
	 *
	 * The wizard posts every field of the step, a blank one included, and has
	 * no current value to show in it. A blank field therefore keeps what the
	 * organisation has rather than erasing it.
	 *
	 * @param array<string, mixed> $fields The posted fields, by wizard field name.
	 *
	 * @return bool True when there was nothing to write or the write succeeded.
	 *
	 * @spec openspec/changes/setup-wizard-board-steps/specs/first-time-setup/spec.md
	 */
	public function write(array $fields): bool {
		$name = trim((string)($fields[self::NAME_FIELD] ?? ''));
		$logo = trim((string)($fields[self::LOGO_FIELD] ?? ''));
		if ($name === '' && $logo === '') {
			return true;
		}

		$organisation = $this->active();
		$mapper = $this->service(class: 'OCA\\OpenRegister\\Db\\OrganisationMapper');
		if ($organisation === null || $mapper === null) {
			return false;
		}

		if ($name !== '') {
			$organisation->setName($name);
		}

		if ($logo !== '') {
			$organisation->setImage($logo);
		}

		try {
			$mapper->update($organisation);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not save the organisation name or logo from the setup wizard',
				['exception' => $e->getMessage()],
			);
			return false;
		}

		return true;
	}//end write()

	/**
	 * The signed-in user's active organisation, or null.
	 *
	 * @return object|null The OpenRegister Organisation entity.
	 */
	private function active(): ?object {
		$service = $this->service(class: 'OCA\\OpenRegister\\Service\\OrganisationService');
		if ($service === null) {
			return null;
		}

		try {
			return $service->getActiveOrganisation();
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not read the active organisation for the setup wizard',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end active()

	/**
	 * Resolve an OpenRegister service when the app is installed.
	 *
	 * @param string $class The class name.
	 *
	 * @return object|null The service, or null when OpenRegister is unavailable.
	 */
	private function service(string $class): ?object {
		if (in_array('openregister', (array)$this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resolve an OpenRegister organisation service',
				['class' => $class, 'exception' => $e->getMessage()],
			);
			return null;
		}
	}//end service()
}//end class
