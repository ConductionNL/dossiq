<?php

/**
 * ActiveOrganisationProfile Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Setup
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Setup;

use OCA\Dossiq\Service\Setup\ActiveOrganisationProfile;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ActiveOrganisationProfile.
 *
 * @covers \OCA\Dossiq\Service\Setup\ActiveOrganisationProfile
 */
class ActiveOrganisationProfileTest extends TestCase {

	/**
	 * A stand-in for OpenRegister's Organisation entity.
	 *
	 * @param string $name  The name.
	 * @param string $image The logo address.
	 *
	 * @return object The organisation.
	 */
	private function organisation(string $name, string $image = ''): object {
		return new class($name, $image) {
			/**
			 * @param string $name  The name.
			 * @param string $image The logo address.
			 */
			public function __construct(public string $name, public string $image) {
			}

			/** @return string */
			public function getName(): string {
				return $this->name;
			}

			/** @return string */
			public function getImage(): string {
				return $this->image;
			}

			/** @param string $name The name. @return void */
			public function setName(string $name): void {
				$this->name = $name;
			}

			/** @param string $image The logo. @return void */
			public function setImage(string $image): void {
				$this->image = $image;
			}
		};

	}//end organisation()

	/**
	 * Build the profile over a container that answers the two OpenRegister services.
	 *
	 * @param object|null $organisation The active organisation, null for none.
	 * @param array<int,object> $updated Receives every organisation the mapper was asked to update.
	 * @param bool $installed Whether OpenRegister is installed.
	 *
	 * @return ActiveOrganisationProfile The profile.
	 */
	private function profile(?object $organisation, array &$updated, bool $installed = true): ActiveOrganisationProfile {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed === true ? ['openregister'] : []);

		$service = new class($organisation) {
			/** @param object|null $organisation The active organisation. */
			public function __construct(private ?object $organisation) {
			}

			/** @return object|null */
			public function getActiveOrganisation(): ?object {
				return $this->organisation;
			}
		};
		$mapper = new class($updated) {
			/** @param array<int,object> $updated The update log. */
			public function __construct(private array &$updated) {
			}

			/** @param object $entity The entity. @return object */
			public function update(object $entity): object {
				$this->updated[] = $entity;
				return $entity;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'OrganisationMapper') === true ? $mapper : $service
		);

		return new ActiveOrganisationProfile(
			appManager: $apps,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end profile()

	/**
	 * The name and logo are read off the active organisation.
	 *
	 * @return void
	 */
	public function testReadsTheActiveOrganisation(): void {
		$updated = [];
		$read = $this->profile($this->organisation('Gemeente Zuiddrecht', 'https://example.org/logo.svg'), $updated)->read();

		$this->assertSame(['available' => true, 'name' => 'Gemeente Zuiddrecht', 'logo' => 'https://example.org/logo.svg'], $read);

	}//end testReadsTheActiveOrganisation()

	/**
	 * Without OpenRegister there is nothing to read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAvailable(): void {
		$updated = [];
		$this->assertFalse($this->profile($this->organisation('X'), $updated, installed: false)->read()['available']);

	}//end testWithoutOpenRegisterNothingIsAvailable()

	/**
	 * A filled field is written; a blank one keeps what the organisation has.
	 *
	 * @return void
	 */
	public function testABlankFieldKeepsTheCurrentValue(): void {
		$updated = [];
		$organisation = $this->organisation('Default Organisation', 'https://example.org/old.svg');

		$ok = $this->profile($organisation, $updated)->write(['organisation_name' => ' Gemeente Waterland ', 'organisation_logo' => '']);

		$this->assertTrue($ok);
		$this->assertCount(1, $updated);
		$this->assertSame('Gemeente Waterland', $organisation->name);
		$this->assertSame('https://example.org/old.svg', $organisation->image);

	}//end testABlankFieldKeepsTheCurrentValue()

	/**
	 * All fields blank writes nothing and succeeds.
	 *
	 * @return void
	 */
	public function testNothingToWriteTouchesNothing(): void {
		$updated = [];
		$this->assertTrue($this->profile($this->organisation('X'), $updated)->write(['organisation_name' => '', 'organisation_logo' => '']));
		$this->assertSame([], $updated);

	}//end testNothingToWriteTouchesNothing()

	/**
	 * A value with no organisation to hold it fails rather than vanishing.
	 *
	 * @return void
	 */
	public function testAValueWithNoOrganisationFails(): void {
		$updated = [];
		$this->assertFalse($this->profile(null, $updated)->write(['organisation_name' => 'Gemeente Waterland']));
		$this->assertSame([], $updated);

	}//end testAValueWithNoOrganisationFails()
	/**
	 * The step is settled by a name, or by there being nothing to name.
	 *
	 * @return void
	 */
	public function testSettledByANameOrByNoOrganisation(): void {
		$updated = [];
		$this->assertTrue($this->profile($this->organisation('Gemeente Zuiddrecht'), $updated)->isSettled());
		$this->assertFalse($this->profile($this->organisation(''), $updated)->isSettled());
		$this->assertTrue($this->profile($this->organisation(''), $updated, installed: false)->isSettled());

	}//end testSettledByANameOrByNoOrganisation()
}//end class
