<?php

/**
 * The upgrade reconciles the grants of every case's private records.
 *
 * Two halves. The class: what it says for each answer of the walk. The
 * registration: the step appinfo/info.xml names is built from that name and
 * run with nobody signed in, as an upgrade runs it, over the register dossiq
 * ships, and the people on the case then hold their grants.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ReconcileCaseRecordGrants;
use OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill;
use OCA\Dossiq\Tests\Support\ShippedPrivateRecords;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\ReconcileCaseRecordGrants
 * @covers \OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\Settings\SchemaSlugMap
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException
 */
class ReconcileCaseRecordGrantsTest extends TestCase {

	private const CASE_ID = '44444444-4444-4444-8444-444444444444';

	private const STEP = 'OCA\\Dossiq\\Repair\\ReconcileCaseRecordGrants';

	/**
	 * What the upgrade output was told, by level.
	 *
	 * @var array{info: list<string>, warning: list<string>}
	 */
	private array $said = ['info' => [], 'warning' => []];

	/**
	 * What went to the log as an error.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	private function repairOutput(): IOutput&MockObject {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said['info'][] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->said['warning'][] = $message;
			}
		);

		return $output;
	}//end repairOutput()

	private function logger(): LoggerInterface&MockObject {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return $logger;
	}//end logger()

	/**
	 * Run the step over a walk that answers this.
	 *
	 * @param array<string, mixed>|RuntimeException $answer The walk's answer, or what it throws.
	 *
	 * @return void
	 */
	private function runOver(array|RuntimeException $answer): void {
		$backfill = $this->createMock(CaseRecordGrantsBackfill::class);
		if ($answer instanceof RuntimeException) {
			$backfill->method('run')->willThrowException($answer);
		} else {
			$backfill->method('run')->willReturn(
				($answer + ['status' => 'reconciled', 'actor' => 'admin', 'cases' => 0, 'granted' => 0, 'revoked' => 0, 'refused' => 0, 'failed' => [], 'unfinished' => []])
			);
		}

		(new ReconcileCaseRecordGrants(backfill: $backfill, logger: $this->logger()))->run($this->repairOutput());
	}//end runOver()

	public function testAFinishedRunSaysItsCountsAndWhoItRanAs(): void {
		$this->runOver(answer: ['cases' => 12, 'granted' => 30, 'revoked' => 4]);

		self::assertSame(['Case record access: reconciled as admin: 12 cases, 30 grants added, 4 revoked, 0 refused.'], $this->said['info']);
		self::assertSame([], $this->said['warning']);
		self::assertSame([], $this->errors);
	}//end testAFinishedRunSaysItsCountsAndWhoItRanAs()

	public function testAnUnfinishedRunIsAWarningAndAnErrorNeverASuccess(): void {
		$failed = array_map(static fn (int $n): string => 'case-' . $n, range(1, 23));
		$this->runOver(answer: ['status' => 'incomplete', 'cases' => 40, 'granted' => 9, 'refused' => 23, 'failed' => $failed, 'unfinished' => ['type-a']]);

		self::assertSame([], $this->said['info'], 'nothing reads as done');
		self::assertCount(1, $this->said['warning']);
		$warning = $this->said['warning'][0];
		self::assertStringContainsString('NOT finished (40 cases, 9 grants added, 0 revoked, 23 refused)', $warning);
		self::assertStringContainsString('23 cases were not fully reconciled: case-1, case-2,', $warning);
		self::assertStringContainsString('case-20 and 3 more.', $warning);
		self::assertStringNotContainsString('case-21', $warning);
		self::assertStringContainsString('Case types with more cases than one run walks: type-a.', $warning);
		self::assertStringContainsString(ReconcileCaseRecordGrants::FALLBACK, $warning);
		self::assertSame(['Dossiq ' . $warning], $this->errors, 'the same sentence is an error in the log');
	}//end testAnUnfinishedRunIsAWarningAndAnErrorNeverASuccess()

	public function testNoServiceAccountNoRightAndNoSharingEachSayNothingWasWritten(): void {
		$this->runOver(answer: ['status' => 'no-service-account', 'actor' => '']);
		$this->runOver(answer: ['status' => 'no-grant-right']);
		$this->runOver(answer: ['status' => 'unavailable']);

		self::assertSame([], $this->said['info']);
		self::assertStringContainsString('NOT reconciled. There is no usable background service account', $this->said['warning'][0]);
		self::assertStringContainsString('no administrator account is used instead', $this->said['warning'][0]);
		self::assertStringContainsString('NOT reconciled. OpenRegister could not give the background service group the special right grant_manage', $this->said['warning'][1]);
		self::assertStringContainsString('NOT reconciled. This OpenRegister has no per-object sharing', $this->said['warning'][2]);
		self::assertCount(3, $this->errors);
	}//end testNoServiceAccountNoRightAndNoSharingEachSayNothingWasWritten()

	public function testAWalkThatThrowsDoesNotStopTheUpgradeAndIsNotASuccess(): void {
		$this->runOver(answer: new RuntimeException('the database went away'));

		self::assertSame([], $this->said['info']);
		self::assertStringContainsString('did NOT finish (the database went away)', $this->said['warning'][0]);
		self::assertCount(1, $this->errors);
	}//end testAWalkThatThrowsDoesNotStopTheUpgradeAndIsNotASuccess()

	/**
	 * The steps of one block of appinfo/info.xml, in order.
	 *
	 * @param string $block The block: post-migration or install.
	 *
	 * @return list<string>
	 */
	private function registered(string $block): array {
		$xml = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertNotFalse($xml);
		$steps = [];
		foreach ($xml->{'repair-steps'}->{$block}->step as $step) {
			$steps[] = trim((string)$step);
		}

		return $steps;
	}//end registered()

	public function testTheUpgradeRunsItAfterTheStepsThatMakeWhatItReads(): void {
		$order = $this->registered(block: 'post-migration');
		$at = array_search(self::STEP, $order, true);
		self::assertNotFalse($at, 'appinfo/info.xml must run the reconcile on upgrade, or an instance waits for an administrator.');

		$providers = ['InitializeSettings', 'SeedStaffGroup', 'InstallShippedPackages', 'PublishSeededCaseTypes', 'CreateDocumentReviews', 'MigrateCaseTeamsToGroups'];
		foreach ($providers as $provider) {
			$providerAt = array_search('OCA\\Dossiq\\Repair\\' . $provider, $order, true);
			self::assertNotFalse($providerAt, $provider);
			self::assertLessThan($at, $providerAt, $provider . ' makes or changes what the reconcile reads, so it runs first.');
		}

		self::assertNotContains(self::STEP, $this->registered(block: 'install'), 'a fresh install has no records to reconcile');
	}//end testTheUpgradeRunsItAfterTheStepsThatMakeWhatItReads()

	public function testTheContainerCanBuildTheRegisteredStep(): void {
		self::assertContains(self::STEP, $this->registered(block: 'post-migration'));
		self::assertTrue(is_subclass_of(self::STEP, IRepairStep::class));

		// Nextcloud builds a repair step from its class name, so every constructor argument
		// down the chain must name a class or an interface the container can resolve.
		foreach ([self::STEP, CaseRecordGrantsBackfill::class] as $class) {
			foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				$type = $parameter->getType();
				self::assertInstanceOf(ReflectionNamedType::class, $type, $class . '::$' . $parameter->getName());
				self::assertFalse($type->isBuiltin(), $class . '::$' . $parameter->getName() . ' is a scalar the container cannot resolve');
				self::assertTrue(class_exists($type->getName()) || interface_exists($type->getName()), $type->getName());
			}
		}
	}//end testTheContainerCanBuildTheRegisteredStep()

	/**
	 * The step appinfo/info.xml names, built from that name over the shipped register.
	 *
	 * @param ShippedPrivateRecords $shipped The shipped register with a case seeded.
	 *
	 * @return IRepairStep
	 */
	private function registeredStepOver(ShippedPrivateRecords $shipped): IRepairStep {
		$class = $this->registered(block: 'post-migration')[array_search(self::STEP, $this->registered(block: 'post-migration'), true)];
		$logger = $this->logger();

		return new $class($shipped->backfill(caseTypeSlug: 'woo-verzoek', logger: $logger), $logger);
	}//end registeredStepOver()

	private function shippedCase(): ShippedPrivateRecords {
		$shipped = new ShippedPrivateRecords($this);
		$shipped->grantsAreChecked = true;
		$shipped->groups = [
			'j.devries' => ['dossiq-medewerkers'],
			'p.bakker' => ['dossiq-medewerkers'],
			'root' => ['admin'],
			'dossiq-achtergrond' => ['dossiq-background-service'],
		];
		$caseType = $shipped->caseType(slug: 'woo-verzoek');
		self::assertNotSame([], $caseType, 'The register ships the Woo request type.');
		$shipped->rows->seed('case', self::CASE_ID, ['caseType' => (string)$caseType['id'], 'assignee' => 'j.devries']);
		$shipped->rows->seed('reviewBatch', 'batch-1', ['case' => self::CASE_ID, 'assignee' => 'p.bakker', '@self' => ['owner' => 'j.devries']]);
		// A review an earlier upgrade made: no owner, no grant.
		$shipped->rows->seed('documentReview', 'review-1', ['case' => self::CASE_ID, 'documentRef' => 'doc-1', 'batch' => 'batch-1', '@self' => ['owner' => '']]);

		return $shipped;
	}//end shippedCase()

	public function testTheRegisteredStepGivesAnExistingReviewItsGrantsWithNobodySignedIn(): void {
		$shipped = $this->shippedCase();
		self::assertFalse($shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'j.devries', action: 'read'), 'before the upgrade the handler is refused');
		self::assertFalse($shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'p.bakker', action: 'update'), 'and so is the reviewer');

		$this->registeredStepOver(shipped: $shipped)->run($this->repairOutput());

		self::assertSame([], $this->said['warning']);
		self::assertSame([], $this->errors);
		self::assertStringContainsString('reconciled as dossiq-achtergrond: 1 cases, 4 grants added', $this->said['info'][0]);
		self::assertTrue($shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'j.devries', action: 'update'), 'the handler marks');
		self::assertTrue($shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'p.bakker', action: 'update'), 'the reviewer reports pages seen');
		self::assertTrue($shipped->admits(schema: 'reviewBatch', uuid: 'batch-1', user: 'p.bakker', action: 'read'));
		self::assertSame(['dossiq-achtergrond', ''], $shipped->sessions, 'it ran as the service account, never as root, and put the empty session back');
		self::assertSame('', $shipped->signedIn, 'the session is clean afterwards');
		self::assertFalse(
			$shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'dossiq-achtergrond', action: 'read'),
			'the service account manages the grants and still cannot read the record'
		);
		self::assertSame(
			[['group' => 'dossiq-background-service', 'register' => 'dossiq', 'schemas' => $this->declaredPrivateSchemas()]],
			$shipped->grantManage,
			'the special right is narrowed to the schemas the case type keeps private'
		);
	}//end testTheRegisteredStepGivesAnExistingReviewItsGrantsWithNobodySignedIn()

	/**
	 * The schema slugs the shipped Woo request type keeps private, as the register declares them.
	 *
	 * @return list<string>
	 */
	private function declaredPrivateSchemas(): array {
		$shipped = new ShippedPrivateRecords($this);
		$slugs = [];
		foreach ((array)($shipped->caseType(slug: 'woo-verzoek')['reviewerAccess']['records'] ?? []) as $record) {
			$slugs[] = (string)(array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY)[$record['schemaKey']] ?? '');
		}

		$slugs = array_values(array_unique(array_filter($slugs)));
		sort($slugs);

		return $slugs;
	}//end declaredPrivateSchemas()

	public function testRunningTheRegisteredStepTwiceChangesNothingTheSecondTime(): void {
		$shipped = $this->shippedCase();
		$step = $this->registeredStepOver(shipped: $shipped);

		$step->run($this->repairOutput());
		$after = $shipped->grants;
		$step->run($this->repairOutput());

		self::assertSame($after, $shipped->grants);
		self::assertStringContainsString('1 cases, 0 grants added, 0 revoked, 0 refused', $this->said['info'][1]);
		self::assertSame([], $this->said['warning']);
	}//end testRunningTheRegisteredStepTwiceChangesNothingTheSecondTime()

	/**
	 * The control: an enabled administrator exists, the service account does not work, and the step refuses to borrow the administrator.
	 *
	 * @dataProvider unusableServiceAccounts
	 */
	public function testWithoutAUsableServiceAccountTheRegisteredStepRefusesToBorrowAnAdministrator(string $configured, array $disabled, bool $member): void {
		$shipped = $this->shippedCase();
		$shipped->serviceAccount = $configured;
		$shipped->disabled = $disabled;
		if ($member === false) {
			$shipped->groups['dossiq-achtergrond'] = [];
		}

		$this->registeredStepOver(shipped: $shipped)->run($this->repairOutput());

		self::assertSame([], $this->said['info']);
		self::assertStringContainsString('NOT reconciled. There is no usable background service account', $this->said['warning'][0]);
		self::assertSame([], $shipped->sessions, 'nobody was signed in: not root, not the account');
		self::assertSame([], $shipped->grants, 'nothing was written');
		self::assertSame([], $shipped->grantManage, 'no right was given');
		self::assertFalse($shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'j.devries', action: 'read'), 'the records still wait');
	}//end testWithoutAUsableServiceAccountTheRegisteredStepRefusesToBorrowAnAdministrator()

	/**
	 * @return array<string, array{0: string, 1: list<string>, 2: bool}>
	 */
	public static function unusableServiceAccounts(): array {
		return [
			'none chosen' => ['', [], true],
			'disabled' => ['dossiq-achtergrond', ['dossiq-achtergrond'], true],
			'not in the group' => ['dossiq-achtergrond', [], false],
			'the administrator chosen, not in the group' => ['root', [], true],
		];
	}//end unusableServiceAccounts()

	public function testWithoutTheSpecialRightTheServiceAccountIsRefusedEveryGrant(): void {
		$shipped = $this->shippedCase();
		$shipped->hasSpecialRights = false;

		$this->registeredStepOver(shipped: $shipped)->run($this->repairOutput());

		self::assertStringContainsString('NOT reconciled. OpenRegister could not give', $this->said['warning'][0]);
		self::assertSame([], $shipped->grants);
	}//end testWithoutTheSpecialRightTheServiceAccountIsRefusedEveryGrant()
}//end class
