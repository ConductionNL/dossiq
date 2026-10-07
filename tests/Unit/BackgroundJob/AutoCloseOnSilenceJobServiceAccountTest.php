<?php

/**
 * Auto-close is off until a case type opts in, and then runs as the account.
 *
 * Cron has no user, so the sweep used to abort as Anonymous and OpenRegister
 * refused every close. Moving it onto the background service account makes
 * auto-close live, so two things must hold first. A case type that only
 * declares a silence period, the state every existing instance is in after an
 * upgrade, closes nothing. And the account may abort a case only when its case
 * type opted in. These tests drive the REAL job, the real silence decision,
 * the real case-type rules and the real role gate over a register that
 * refuses a write from nobody. Only the ending act's own bookkeeping is a
 * double, and it asks the real gate before it records the close.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\AutoCloseOnSilenceJob;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only an opted-in case type's silent case closes, and it closes as the account.
 *
 * @covers \OCA\Dossiq\BackgroundJob\AutoCloseOnSilenceJob
 * @covers \OCA\Dossiq\Service\Lifecycle\LifecycleActorGate
 * @covers \OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules
 * @uses \OCA\Dossiq\Service\Lifecycle\SilenceCloseService
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class AutoCloseOnSilenceJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * A case type that opted in to closing silent cases.
	 *
	 * @var string
	 */
	private const OPTED_IN = '11111111-1111-4111-8111-111111111111';

	/**
	 * A case type that only declares a period, as every case type did before.
	 *
	 * @var string
	 */
	private const PERIOD_ONLY = '22222222-2222-4222-8222-222222222222';

	/**
	 * The register the job reads and writes.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Every close the ending act recorded, as `uid:caseId`.
	 *
	 * @var array<int, string>
	 */
	private array $aborted = [];

	/**
	 * Seed both case types and one case of each, silent for 40 days.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->aborted = [];
		$schema = new RealSchemaValidator();

		$types = [
			self::OPTED_IN => ['autoCloseOnSilence' => true, 'autoCloseAfterSilenceDays' => 30],
			self::PERIOD_ONLY => ['autoCloseAfterSilenceDays' => 30],
		];
		foreach ($types as $id => $row) {
			$row['title'] = 'Type '.$id;
			$this->assertSame([], $schema->errors(slug: 'caseType', payload: $row, creating: false));
			$this->register->seed(schema: 'caseType', id: $id, row: $row);
		}

		$silentSince = (new DateTimeImmutable('today'))->modify('-40 days')->format('c');
		$this->seedCase(id: 'case-opted-in', caseType: self::OPTED_IN, silentSince: $silentSince);
		$this->seedCase(id: 'case-period-only', caseType: self::PERIOD_ONLY, silentSince: $silentSince);
	}//end setUp()

	/**
	 * After an upgrade, a case type that only declares a period closes nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDidNotOptInClosesNothing(): void {
		unset($this->register->rows['case']['case-opted-in']);

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->aborted);
		$this->assertSame([], $this->register->writes, 'Not even a warning is written.');
		$this->assertSame([], $this->register->refusals);
	}//end testACaseTypeThatDidNotOptInClosesNothing()

	/**
	 * The opted-in type's silent case closes as the account, the other does not.
	 *
	 * @return void
	 */
	public function testOnlyTheOptedInCaseClosesAndItClosesAsTheAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertSame(['dossiq-achtergrond:case-opted-in'], $this->aborted);
		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$this->assertSame(['case-opted-in'], array_values(array_unique(array_column($this->register->writes, 2))));
	}//end testOnlyTheOptedInCaseClosesAndItClosesAsTheAccount()

	/**
	 * The account may abort an opted-in case type's case and nothing else.
	 *
	 * @return void
	 */
	public function testTheAccountMayAbortOnlyAnOptedInCaseType(): void {
		$gate = $this->gate();
		$this->acting = $this->backgroundUser(uid: 'dossiq-achtergrond');

		$this->assertTrue($gate->may(act: 'abort', case: ['caseType' => self::OPTED_IN]));
		$this->assertFalse($gate->may(act: 'abort', case: ['caseType' => self::PERIOD_ONLY]));
		$this->assertFalse($gate->may(act: 'finish', case: ['caseType' => self::OPTED_IN]));
		$this->assertFalse($gate->may(act: 'archive', case: ['caseType' => self::OPTED_IN]));
	}//end testTheAccountMayAbortOnlyAnOptedInCaseType()

	/**
	 * A handler keeps the abort the case type gives everyone.
	 *
	 * @return void
	 */
	public function testAHandlerIsNotTouchedByTheOptIn(): void {
		$gate = $this->gate();
		$this->acting = $this->backgroundUser(uid: 'behandelaar');

		$this->assertTrue($gate->may(act: 'abort', case: ['caseType' => self::PERIOD_ONLY]));
		$this->assertTrue($gate->may(act: 'finish', case: ['caseType' => self::PERIOD_ONLY]));
	}//end testAHandlerIsNotTouchedByTheOptIn()

	/**
	 * Without an account nothing is read, written or closed.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountNothingCloses(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->aborted);
		$this->assertSame([], $this->register->writes);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountNothingCloses()

	/**
	 * Seed one open case whose last journal entry is the given moment.
	 *
	 * @param string $id          The case id.
	 * @param string $caseType    The case type uuid.
	 * @param string $silentSince The last activity.
	 *
	 * @return void
	 */
	private function seedCase(string $id, string $caseType, string $silentSince): void {
		$this->register->seed(
			schema: 'case',
			id: $id,
			row: [
				'title' => 'Case '.$id,
				'caseType' => $caseType,
				'isFinalStatus' => false,
				'isDraft' => false,
				'activity' => json_encode([['type' => 'suspend', 'at' => $silentSince]]),
			]
		);
	}//end seedCase()

	/**
	 * The settings bridge over the refusing register.
	 *
	 * @return SettingsService The bridge.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				default => $default,
			}
		);

		return $settings;
	}//end settings()

	/**
	 * The real role gate over the real case-type rules and the real account.
	 *
	 * @return LifecycleActorGate The gate.
	 */
	private function gate(): LifecycleActorGate {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);

		return new LifecycleActorGate(
			rules: new LifecycleCaseTypeRules(settingsService: $this->settings()),
			groupManager: $groups,
			userSession: $this->backgroundSession(),
			logger: new NullLogger(),
			serviceAccount: $this->backgroundAccount(),
		);
	}//end gate()

	/**
	 * The real job, the real silence decision, the real gate.
	 *
	 * @return AutoCloseOnSilenceJob The job.
	 */
	private function job(): AutoCloseOnSilenceJob {
		$gate = $this->gate();
		$register = $this->register;

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturnCallback(
			static fn (string $caseId): ?array => ($register->rows['case'][$caseId] ?? null)
		);
		$store->method('saveCase')->willReturnCallback(
			static fn (array $case): array => $register->saveObject(object: $case, schema: 'case', uuid: (string)$case['id'])
		);

		$endings = $this->createMock(CaseEndingActs::class);
		$endings->method('abort')->willReturnCallback(
			function (string $caseId) use ($gate, $register): array {
				$case = $register->rows['case'][$caseId];
				$gate->require(act: 'abort', case: $case);
				$this->aborted[] = $this->actingUid().':'.$caseId;
				$case['isFinalStatus'] = true;
				$register->saveObject(object: $case, schema: 'case', uuid: $caseId);

				return ['caseId' => $caseId];
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		return new AutoCloseOnSilenceJob(
			time: $this->createMock(ITimeFactory::class),
			silence: new SilenceCloseService(
				store: $store,
				rules: new LifecycleCaseTypeRules(settingsService: $this->settings()),
				endings: $endings,
				journal: new CaseJournal(userSession: $this->backgroundSession()),
				dates: $this->caseDates(),
				logger: new NullLogger(),
			),
			settingsService: $this->settings(),
			appManager: $apps,
			logger: new NullLogger(),
			serviceAccount: $this->backgroundAccount(),
		);
	}//end job()
}//end class
