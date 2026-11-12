<?php

/**
 * Applicant portal acts unknown case test
 *
 * Portaliq names the case of a resident's act; when it is not a case in
 * dossiq's own register (a blank id, an unconfigured register or schema, or
 * a row that is not an object), nothing is recorded and nobody is notified.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Portal\ApplicantPortalActs
 */
class ApplicantPortalActsUnknownCaseTest extends TestCase {

	/**
	 * The configured values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => '7', 'case_schema' => '12'];

	/**
	 * The recorder over an object service; the timeline and notifications must stay untouched.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 *
	 * @return ApplicantPortalActs
	 */
	private function acts(ObjectServiceInterface $objects): ApplicantPortalActs {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));

		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects($this->never())->method('record');
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');

		return new ApplicantPortalActs($settings, $timeline, $notifications, $this->createMock(LoggerInterface::class));
	}//end acts()

	/**
	 * A blank case id is never looked up.
	 *
	 * @return void
	 */
	public function testABlankCaseIdRecordsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('find');

		$this->assertFalse($this->acts($objects)->recordWithdrawal(' ', 'withdrawn', 'reason', '2026-05-01T10:00:00Z'));
		$this->assertFalse($this->acts($objects)->recordWrite('', 'amendment', ['title'], '2026-05-01T10:00:00Z'));
	}//end testABlankCaseIdRecordsNothing()

	/**
	 * Without a configured case schema the case is not ours to read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaRecordsNothing(): void {
		$this->config['case_schema'] = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('find');

		$this->assertFalse($this->acts($objects)->recordWithdrawal('case-1', 'withdrawn', 'reason', '2026-05-01T10:00:00Z'));
	}//end testAnUnconfiguredSchemaRecordsNothing()

	/**
	 * A case that is not found, or serialises to something other than an array, records nothing.
	 *
	 * @return void
	 */
	public function testACaseThatDoesNotReadAsAnArrayRecordsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('find')->with('case-1', $this->anything(), $this->anything(), '7', '12')->willReturn(null);
		$this->assertFalse($this->acts($objects)->recordWithdrawal('case-1', 'withdrawn', 'reason', '2026-05-01T10:00:00Z'));

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn('not an array');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($entity);
		$this->assertFalse($this->acts($objects)->recordWithdrawal('case-1', 'withdrawn', 'reason', '2026-05-01T10:00:00Z'));
	}//end testACaseThatDoesNotReadAsAnArrayRecordsNothing()
}//end class
