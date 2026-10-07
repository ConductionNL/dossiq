<?php

/**
 * The background service account is wired: grant, repair step, setup check.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ServiceAccount
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ServiceAccount;

use OCA\Dossiq\Repair\CreateBackgroundServiceGroup;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\SetupCheck\BackgroundServiceAccountCheck;
use OCP\IL10N;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Wiring of the background service account.
 *
 * @covers \OCA\Dossiq\Repair\CreateBackgroundServiceGroup
 * @covers \OCA\Dossiq\SetupCheck\BackgroundServiceAccountCheck
 */
class BackgroundServiceAccountWiringTest extends TestCase {

	/**
	 * The schemas the reminder sweep writes grant the group create and update,
	 * and keep every logged-in user and anonymous reads as the open default had.
	 *
	 * @return void
	 */
	public function testTheSweptSchemasGrantTheGroupAndKeepTheOpenDefault(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../../lib/Settings/register.d/90-background-service-account.json'),
			true
		);

		foreach (['deadlineInstance', 'termijnGebeurtenis'] as $slug) {
			$auth = $fragment['components']['schemas'][$slug]['authorization'];
			$this->assertContains(BackgroundServiceAccount::GROUP, $auth['create'], $slug);
			$this->assertContains(BackgroundServiceAccount::GROUP, $auth['update'], $slug);
			foreach (['create', 'read', 'update', 'delete'] as $action) {
				$this->assertContains('authenticated', $auth[$action], $slug.' '.$action);
			}

			$this->assertContains('public', $auth['read'], $slug);
		}
	}//end testTheSweptSchemasGrantTheGroupAndKeepTheOpenDefault()

	/**
	 * Every schema the other background jobs and the timer listener write
	 * grants the group create and update, keeps every logged-in user, and
	 * does not open reads to anonymous callers.
	 *
	 * @return void
	 */
	public function testTheJobSchemasGrantTheGroupAndStayClosedToAnonymousReads(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../../lib/Settings/register.d/90-background-service-account.json'),
			true
		);

		$written = [
			'adviesAanvraag',
			'aanvullingsverzoek',
			'beschikking',
			'bezwaarTrigger',
			'caseBerichtenboxMessage',
			'caseDocument',
			'klantSentiment',
			'mailIntakeEntry',
			'penaltyPaymentCalculation',
			'specialistBeschikbaarheid',
			'stateMachineLog',
			'stufMessage',
			'tenantQuota',
		];
		foreach ($written as $slug) {
			$auth = $fragment['components']['schemas'][$slug]['authorization'];
			$this->assertContains(BackgroundServiceAccount::GROUP, $auth['create'], $slug);
			$this->assertContains(BackgroundServiceAccount::GROUP, $auth['update'], $slug);
			foreach (['create', 'read', 'update', 'delete'] as $action) {
				$this->assertContains('authenticated', $auth[$action], $slug.' '.$action);
			}

			$this->assertNotContains('public', $auth['read'], $slug);
		}

		$this->assertArrayNotHasKey('case', $fragment['components']['schemas']);
	}//end testTheJobSchemasGrantTheGroupAndStayClosedToAnonymousReads()

	/**
	 * The repair step is registered in both the upgrade and the install block.
	 *
	 * @return void
	 */
	public function testTheRepairStepRunsOnUpgradeAndInstall(): void {
		$xml = (string)file_get_contents(__DIR__.'/../../../../appinfo/info.xml');
		$this->assertSame(2, substr_count($xml, '<step>'.CreateBackgroundServiceGroup::class.'</step>'));
	}//end testTheRepairStepRunsOnUpgradeAndInstall()

	/**
	 * The repair step creates the group and warns while no account is picked.
	 *
	 * @return void
	 */
	public function testTheRepairStepWarnsWhileNoAccountIsPicked(): void {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('group')->willReturn(BackgroundServiceAccount::GROUP);
		$account->method('ensureGroup')->willReturn($this->createMock(\OCP\IGroup::class));
		$account->method('configuredUserId')->willReturn('');
		$account->expects($this->never())->method('assign');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('no service account'));

		(new CreateBackgroundServiceGroup(serviceAccount: $account))->run($output);
	}//end testTheRepairStepWarnsWhileNoAccountIsPicked()

	/**
	 * The setup check warns while the account cannot be used.
	 *
	 * @return void
	 */
	public function testTheSetupCheckWarnsWhileTheAccountCannotBeUsed(): void {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('status')->willReturn(['userId' => '', 'usable' => false, 'reason' => 'unset']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$result = (new BackgroundServiceAccountCheck(serviceAccount: $account, l10n: $l10n))->run();

		$this->assertSame('warning', $result->getSeverity());
		$this->assertStringContainsString('No account is chosen.', (string)$result->getDescription());
	}//end testTheSetupCheckWarnsWhileTheAccountCannotBeUsed()
}//end class
