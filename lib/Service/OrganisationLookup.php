<?php

/**
 * Finds OpenRegister's OrganisationMapper and reads Organisations by uuid.
 *
 * The tenant and partner migrations, the satellite orphan scan and the
 * tenant resolver each resolved the mapper the same way: only when
 * OpenRegister is installed, logging at error when the container cannot
 * build it. Each caller keeps its own failure message.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves OpenRegister's OrganisationMapper and reads Organisations through it.
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md#requirement-the-active-tenant-is-openregisters-active-organisation-req-tao-002
 */
final class OrganisationLookup {
	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Detects whether OpenRegister is installed.
	 * @param ContainerInterface $container  DI container, resolves OpenRegister's OrganisationMapper.
	 * @param LoggerInterface    $logger     Logger for a mapper that cannot be resolved.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve OpenRegister's OrganisationMapper when the app is installed.
	 *
	 * @param string $failureMessage The error logged when the container cannot build the mapper.
	 *
	 * @return object|null The mapper, or null when OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md#requirement-the-active-tenant-is-openregisters-active-organisation-req-tao-002
	 */
	public function mapper(string $failureMessage): ?object {
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (Throwable $e) {
			$this->logger->error(
				$failureMessage,
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end mapper()

	/**
	 * Find an Organisation by uuid, returning null when absent.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param string $uuid   Uuid to look up.
	 *
	 * @return object|null The Organisation, or null when none matches.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md#requirement-the-active-tenant-is-openregisters-active-organisation-req-tao-002
	 */
	public static function byUuid(object $mapper, string $uuid): ?object {
		try {
			return $mapper->findByUuid($uuid);
		} catch (Throwable $e) {
			// DoesNotExistException, and any other lookup failure, reads as absent.
			return null;
		}
	}//end byUuid()
}//end class
