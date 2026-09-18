<?php

/**
 * The admin bullet of the case-access policy.
 *
 * `canUserAccessCase()` documented four ways in and implemented three: the
 * admin one was written down and never checked. Every case detail page eagerly
 * loads its access links, so an admin opening a case they were not assigned to
 * got a 403 and a red "Could not load the links on this case" toast on a page
 * they administer.
 *
 * Driven over a REAL OpenRegisterSharingGateway with a hand-written fake
 * ObjectService, which also counts its reads — the admin branch has to answer
 * before the case is fetched, and a call count is the only way to show that.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake of OpenRegister's ObjectService that counts what it was asked for.
 */
final class CapFakeObjectService {

	/**
	 * The stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $objects = [];

	/**
	 * How many times a case was fetched.
	 *
	 * @var int
	 */
	public int $finds = 0;

	/**
	 * One case by id.
	 *
	 * @param int|string $id      The case id.
	 * @param mixed      ...$args The register/schema the production call names.
	 *
	 * @return array<string, mixed>|null The case, or null.
	 */
	public function find($id, ...$args) {
		unset($args);
		$this->finds++;
		return ($this->objects[(string)$id] ?? null);
	}//end find()

	/**
	 * Matching objects. No share schema is configured here, so this is never
	 * reached; declared because the policy's share lookup would call it.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return array<int, array<string, mixed>> Always empty.
	 */
	public function findAll(array $config): array {
		unset($config);
		return [];
	}//end findAll()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Sharing\CaseAccessPolicy
 *
 * @uses \OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway
 */
class CaseAccessPolicyAdminTest extends TestCase {

	/**
	 * The fake OpenRegister object store.
	 *
	 * @var CapFakeObjectService
	 */
	private CapFakeObjectService $objects;

	/**
	 * The group manager backing the admin decision.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * The policy under test.
	 *
	 * @var CaseAccessPolicy
	 */
	private CaseAccessPolicy $policy;

	/**
	 * Build the policy over a real gateway, with `admin` the only admin.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CapFakeObjectService();
		$this->objects->objects['case-1'] = [
			'id' => 'case-1',
			'title' => 'Dakkapel Kerkstraat 12',
			'assignee' => 'jane',
		];

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objects;
				}

				throw new \RuntimeException('no ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		// A register and a case schema, but NO share schema: the policy's last
		// bullet then answers "no share found" without a lookup, so each test
		// turns only on the bullet it is about.
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register', 'case_schema' => '1',
					default => '',
				};
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			static function (string $userId): bool {
				return $userId === 'admin';
			}
		);

		$this->policy = new CaseAccessPolicy(
			$settings,
			new OpenRegisterSharingGateway($appManager, $container, $logger),
			$this->groupManager,
			$logger
		);
	}//end setUp()

	/**
	 * An admin may act on a case nobody assigned them to.
	 *
	 * @return void
	 */
	public function testAnAdminMayActOnACaseTheyWereNeverAssigned(): void {
		self::assertTrue($this->policy->canUserAccessCase('case-1', 'admin'));
	}//end testAnAdminMayActOnACaseTheyWereNeverAssigned()

	/**
	 * And answers before the case is read: the check costs no round trip, which
	 * matters on a page that fires it for every case it opens.
	 *
	 * @return void
	 */
	public function testTheAdminAnswerCostsNoCaseLookup(): void {
		$this->policy->canUserAccessCase('case-1', 'admin');
		self::assertSame(0, $this->objects->finds);
	}//end testTheAdminAnswerCostsNoCaseLookup()

	/**
	 * A stranger is still refused — the admin bullet widens the policy, it does
	 * not open it.
	 *
	 * @return void
	 */
	public function testAStrangerIsStillRefused(): void {
		self::assertFalse($this->policy->canUserAccessCase('case-1', 'bob'));
		self::assertSame(1, $this->objects->finds);
	}//end testAStrangerIsStillRefused()

	/**
	 * The assignee still passes, on the bullet that was already implemented.
	 *
	 * @return void
	 */
	public function testTheAssigneeStillPasses(): void {
		self::assertTrue($this->policy->canUserAccessCase('case-1', 'jane'));
	}//end testTheAssigneeStillPasses()

	/**
	 * A case that does not exist is refused, admin or not — there is nothing to
	 * be assigned to and nothing to load links for.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsRefusedForANonAdmin(): void {
		self::assertFalse($this->policy->canUserAccessCase('case-nope', 'bob'));
	}//end testAMissingCaseIsRefusedForANonAdmin()
}//end class
