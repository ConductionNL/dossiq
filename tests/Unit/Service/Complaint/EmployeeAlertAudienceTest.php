<?php

/**
 * EmployeeAlertAudience tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\Complaint\EmployeeAlertAudience;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the employee complaint alert audience.
 *
 * @covers \OCA\Dossiq\Service\Complaint\EmployeeAlertAudience
 */
class EmployeeAlertAudienceTest extends TestCase {

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * App config mock.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);
	}//end setUp()

	/**
	 * The audience under test.
	 *
	 * @return EmployeeAlertAudience
	 */
	private function audience(): EmployeeAlertAudience {
		return new EmployeeAlertAudience($this->groupManager, $this->appConfig);
	}//end audience()

	/**
	 * A member of a default audience group may read the alerts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function testAMemberOfTheDepartmentHeadGroupMayRead(): void {
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === 'department-heads'
		);

		$this->assertTrue($this->audience()->mayReadEmployeeAlerts('head-1'));
	}//end testAMemberOfTheDepartmentHeadGroupMayRead()

	/**
	 * A complaint handler outside the groups may not, and neither may an
	 * administrator who is not in a group.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function testAHandlerOrAnAdminOutsideTheGroupsMayNotRead(): void {
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->assertFalse($this->audience()->mayReadEmployeeAlerts('handler-1'));
	}//end testAHandlerOrAnAdminOutsideTheGroupsMayNotRead()

	/**
	 * No session never reads, and a failing group lookup refuses.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function testNoSessionOrAFailingLookupRefuses(): void {
		$this->groupManager->method('isInGroup')->willThrowException(new RuntimeException('backend down'));

		$this->assertFalse($this->audience()->mayReadEmployeeAlerts(''));
		$this->assertFalse($this->audience()->mayReadEmployeeAlerts('hr-1'));
	}//end testNoSessionOrAFailingLookupRefuses()

	/**
	 * The configured groups replace the defaults; blanks are dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function testConfiguredGroupsReplaceTheDefaults(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(' personeelszaken , ,afdelingshoofden');

		$audience = new EmployeeAlertAudience($this->groupManager, $appConfig);

		$this->assertSame(['personeelszaken', 'afdelingshoofden'], $audience->groups());
	}//end testConfiguredGroupsReplaceTheDefaults()
}//end class
