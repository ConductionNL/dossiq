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
 * @spec openspec/specs/first-time-setup/spec.md
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
 * @spec openspec/specs/first-time-setup/spec.md
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
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function read(): array {
		$unavailable = ['available' => false, 'name' => '', 'logo' => ''];
		try {
			$organisation = $this->active();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the setup wizard could not read the active organisation from OpenRegister',
				['exception' => $e->getMessage()],
			);
			return $unavailable;
		}

		if ($organisation === null) {
			return $unavailable;
		}

		return [
			'available' => true,
			'name' => (string)($organisation->getName() ?? ''),
			'logo' => (string)($organisation->getImage() ?? ''),
		];
	}//end read()

	/**
	 * Whether the wizard's Organisation step is settled: the active
	 * organisation has a name, or there is no organisation to name.
	 *
	 * @return bool True when settled.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function isSettled(): bool {
		$profile = $this->read();

		return $profile['available'] === false || $profile['name'] !== '';
	}//end isSettled()

	/**
	 * Write the fields the wizard posted onto the active organisation.
	 *
	 * The wizard posts every field of the step, a blank one included, and has
	 * no current value to show in it. A blank field therefore keeps what the
	 * organisation has rather than erasing it. Only the active organisation is
	 * written; no id from the request is read. A logo that is not an http(s)
	 * URL is refused.
	 *
	 * @param array<string, mixed> $fields The posted fields, by wizard field name.
	 *
	 * @return bool True when there was nothing to write or the write succeeded.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function write(array $fields): bool {
		$name = trim((string)($fields[self::NAME_FIELD] ?? ''));
		$logo = trim((string)($fields[self::LOGO_FIELD] ?? ''));
		if ($name === '' && $logo === '') {
			return true;
		}

		if ($logo !== '' && $this->isWebAddress(url: $logo) === false) {
			return false;
		}

		try {
			$organisation = $this->active();
			if ($organisation === null) {
				return false;
			}

			if ($name !== '') {
				$organisation->setName($name);
			}

			if ($logo !== '') {
				$organisation->setImage($logo);
			}

			$this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper')->update($organisation);
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
	 * Whether a logo address is an http or https URL.
	 *
	 * The logo is drawn as an image source, so a `javascript:` or `data:`
	 * address is refused.
	 *
	 * @param string $url The address.
	 *
	 * @return bool True for an http(s) URL with a host.
	 */
	private function isWebAddress(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		$host = (string)parse_url($url, PHP_URL_HOST);

		return in_array($scheme, ['http', 'https'], true) === true
			&& $host !== ''
			&& filter_var($url, FILTER_VALIDATE_URL) !== false;
	}//end isWebAddress()

	/**
	 * The signed-in user's active organisation.
	 *
	 * @return object|null The OpenRegister Organisation entity, or null when OpenRegister is not installed or there is none.
	 *
	 * @throws \Throwable When OpenRegister cannot be asked.
	 */
	private function active(): ?object {
		if (in_array('openregister', (array)$this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		return $this->container->get('OCA\\OpenRegister\\Service\\OrganisationService')->getActiveOrganisation();
	}//end active()
}//end class
