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
	 * The schemas the reminder sweep writes grant the group create, read and
	 * update, and the dossiq staff group everything. Since
	 * case-access-by-handling-team (Ruben, 2026-10-11) neither every logged-in
	 * user nor an anonymous caller is granted anything here.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function testTheSweptSchemasGrantTheGroupAndTheStaffOnly(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../../lib/Settings/register.d/90-background-service-account.json'),
			true
		);

		foreach (['deadlineInstance', 'termijnGebeurtenis'] as $slug) {
			$auth = $fragment['components']['schemas'][$slug]['authorization'];
			$this->assertStaffAndBackgroundOnly(auth: $auth, slug: $slug);
		}
	}//end testTheSweptSchemasGrantTheGroupAndTheStaffOnly()

	/**
	 * The background group writes and reads; the staff group holds every verb; nobody else.
	 *
	 * @param array<string, array<int, string>> $auth The block.
	 * @param string                            $slug The schema, for the message.
	 *
	 * @return void
	 */
	private function assertStaffAndBackgroundOnly(array $auth, string $slug): void {
		foreach (['create', 'read', 'update'] as $action) {
			$this->assertSame(['dossiq-medewerkers', BackgroundServiceAccount::GROUP], $auth[$action], $slug.' '.$action);
		}

		$this->assertSame(['dossiq-medewerkers'], $auth['delete'], $slug.' delete');
	}//end assertStaffAndBackgroundOnly()

	/**
	 * Every schema the other background jobs and the timer listener write
	 * grants the group create, read and update and the staff group every verb,
	 * and nobody else (case-access-by-handling-team).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function testTheJobSchemasGrantTheGroupAndTheStaffOnly(): void {
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
			$this->assertStaffAndBackgroundOnly(auth: $auth, slug: $slug);
		}

		$this->assertArrayNotHasKey('case', $fragment['components']['schemas']);
	}//end testTheJobSchemasGrantTheGroupAndTheStaffOnly()

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
