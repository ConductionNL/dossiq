<?php

/**
 * Organisation Lookup test
 *
 * Pins the mapper resolution the tenant and partner migrations, the orphan
 * scan and the tenant resolver share: only when OpenRegister is installed,
 * logging the caller's message when the container cannot build the mapper,
 * and a failed uuid read answering absent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\OrganisationLookup;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests for OrganisationLookup.
 */
class OrganisationLookupTest extends TestCase {
	/**
	 * Without OpenRegister installed there is no mapper, and the container is not asked.
	 *
	 * @return void
	 */
	public function testMapperIsNullWhenOpenRegisterIsNotInstalled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['files']);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$lookup = new OrganisationLookup($appManager, $container, $logger);

		self::assertNull($lookup->mapper('unused'));
	}//end testMapperIsNullWhenOpenRegisterIsNotInstalled()

	/**
	 * With OpenRegister installed the container's mapper is returned.
	 *
	 * @return void
	 */
	public function testMapperComesFromTheContainer(): void {
		$mapper = new stdClass();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with('OCA\\OpenRegister\\Db\\OrganisationMapper')
			->willReturn($mapper);

		$lookup = new OrganisationLookup($appManager, $container, $this->createMock(LoggerInterface::class));

		self::assertSame($mapper, $lookup->mapper('unused'));
	}//end testMapperComesFromTheContainer()

	/**
	 * A container failure answers null and logs the caller's own message at error.
	 *
	 * @return void
	 */
	public function testMapperFailureLogsTheCallersMessage(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Dossiq: caller message', ['exception' => 'boom']);

		$lookup = new OrganisationLookup($appManager, $container, $logger);

		self::assertNull($lookup->mapper('Dossiq: caller message'));
	}//end testMapperFailureLogsTheCallersMessage()

	/**
	 * A uuid read returns the mapper's Organisation, and a failed read answers null.
	 *
	 * @return void
	 */
	public function testByUuidAnswersTheOrganisationOrNull(): void {
		$organisation = new stdClass();
		$found = new class ($organisation) {
			/**
			 * Constructor.
			 *
			 * @param object $organisation The Organisation to answer.
			 */
			public function __construct(private readonly object $organisation) {
			}//end __construct()

			/**
			 * Find one Organisation by uuid.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object The Organisation.
			 */
			public function findByUuid(string $uuid): object {
				return $this->organisation;
			}//end findByUuid()
		};
		$missing = new class () {
			/**
			 * Find one Organisation by uuid, failing.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object Never returns.
			 */
			public function findByUuid(string $uuid): object {
				throw new RuntimeException('not found');
			}//end findByUuid()
		};

		self::assertSame($organisation, OrganisationLookup::byUuid($found, 'org-1'));
		self::assertNull(OrganisationLookup::byUuid($missing, 'org-1'));
	}//end testByUuidAnswersTheOrganisationOrNull()
}//end class
