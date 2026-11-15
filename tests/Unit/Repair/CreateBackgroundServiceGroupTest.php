<?php

/**
 * The background jobs' group is created and the configured service account put in it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use InvalidArgumentException;
use OCA\Dossiq\Repair\CreateBackgroundServiceGroup;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\CreateBackgroundServiceGroup
 */
class CreateBackgroundServiceGroupTest extends TestCase {

	/**
	 * What the step told the operator, prefixed with the level.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * An output that records every line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = 'info: ' . $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said[] = 'warning: ' . $line;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * A service account answering to group 'dossiq-background'.
	 *
	 * @return BackgroundServiceAccount&MockObject The account.
	 */
	private function account(): BackgroundServiceAccount&MockObject {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('group')->willReturn('dossiq-background');

		return $account;
	}//end account()

	/**
	 * The configured account is assigned to the group, and the operator told so.
	 *
	 * @return void
	 */
	public function testTheConfiguredAccountIsAssignedToTheGroup(): void {
		$account = $this->account();
		$account->expects($this->once())->method('ensureGroup');
		$account->method('configuredUserId')->willReturn('svc');
		$account->expects($this->once())->method('assign')->with('svc')->willReturn([]);

		(new CreateBackgroundServiceGroup($account))->run($this->recordingOutput());

		self::assertSame(['info: The background service account svc is in group dossiq-background.'], $this->said);
	}//end testTheConfiguredAccountIsAssignedToTheGroup()

	/**
	 * A group that cannot be created stops the step before any account is touched.
	 *
	 * @return void
	 */
	public function testAGroupThatCannotBeCreatedStopsTheStep(): void {
		$account = $this->account();
		$account->method('ensureGroup')->willThrowException(new InvalidArgumentException('a user holds that name'));
		$account->expects($this->never())->method('configuredUserId');
		$account->expects($this->never())->method('assign');

		(new CreateBackgroundServiceGroup($account))->run($this->recordingOutput());

		self::assertSame(['warning: Could not create group dossiq-background: a user holds that name'], $this->said);
	}//end testAGroupThatCannotBeCreatedStopsTheStep()

	/**
	 * With no account configured, nothing is assigned and the operator is asked to pick one.
	 *
	 * @return void
	 */
	public function testNoConfiguredAccountAssignsNothingAndWarns(): void {
		$account = $this->account();
		$account->method('configuredUserId')->willReturn('');
		$account->expects($this->never())->method('assign');

		(new CreateBackgroundServiceGroup($account))->run($this->recordingOutput());

		self::assertCount(1, $this->said);
		self::assertStringStartsWith('warning: The Dossiq background jobs have no service account yet', $this->said[0]);
		self::assertStringContainsString('Pick one in the Dossiq settings.', $this->said[0]);
	}//end testNoConfiguredAccountAssignsNothingAndWarns()

	/**
	 * An account the group refuses is reported, not thrown.
	 *
	 * @return void
	 */
	public function testARefusedAccountIsReportedAsAWarning(): void {
		$account = $this->account();
		$account->method('configuredUserId')->willReturn('ghost');
		$account->method('assign')->willThrowException(new InvalidArgumentException('no such user'));

		(new CreateBackgroundServiceGroup($account))->run($this->recordingOutput());

		self::assertSame(['warning: The background service account ghost cannot be used: no such user'], $this->said);
	}//end testARefusedAccountIsReportedAsAWarning()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesTheGroupItCreates(): void {
		self::assertSame(
			'Create the group the Dossiq background jobs write with',
			(new CreateBackgroundServiceGroup($this->account()))->getName()
		);
	}//end testTheStepNamesTheGroupItCreates()
}//end class
