<?php

/**
 * PaymentStateProjectionJob: when it writes nothing, and what it reports.
 *
 * The service-account test drives the run that writes. This drives the
 * branches that must NOT write: no money app, no case register configured, a
 * read that came back stale, a state that did not change, and a case whose
 * patch fails while the others still go through. The register and the job are
 * real; the shillinq read and the logger are the doubles.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob;
use OCA\Dossiq\Kernel\Contracts\CasePaymentStateInterface;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The projection writes only a changed, readable state, and one failing case
 * does not stop the others.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\BackgroundJob\OpenCaseSweep
 * @uses \OCA\Dossiq\Service\EmailArchivalService
 */
class PaymentStateProjectionJobBranchesTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job reads and writes.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * The case ids shillinq was asked about, in order.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * What shillinq answers, per case id.
	 *
	 * @var array<string, string>
	 */
	private array $states = [];

	/**
	 * Two open cases, both outstanding.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->asked = [];
		$this->register = $this->refusingRegister();
		$this->seedTwoCases();
		$this->states = ['case-1' => CasePaymentState::PAID, 'case-2' => CasePaymentState::PAID];
	}//end setUp()

	/**
	 * Without the money app nothing is asked or written.
	 *
	 * @return void
	 */
	public function testWithoutTheMoneyAppNothingIsReadOrWritten(): void {
		$this->runJobOnce(job: $this->job(moneyApp: false));

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->register->writes);
	}//end testWithoutTheMoneyAppNothingIsReadOrWritten()

	/**
	 * Without a configured case schema there is no sweep.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaNothingIsReadOrWritten(): void {
		$this->runJobOnce(job: $this->job(caseSchema: ''));

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->register->writes);
	}//end testWithoutACaseSchemaNothingIsReadOrWritten()

	/**
	 * A stale read writes nothing: the last known state stays.
	 *
	 * @return void
	 */
	public function testAStaleReadKeepsTheLastKnownState(): void {
		$this->states['case-1'] = CasePaymentStateInterface::STALE;

		$this->runJobOnce(job: $this->job());

		$this->assertSame(['case-1', 'case-2'], $this->asked);
		$this->assertSame(CasePaymentState::OUTSTANDING, $this->register->row(schema: 'case', id: 'case-1')['paymentState']);
		$this->assertArrayNotHasKey('paymentStateCheckedAt', $this->register->row(schema: 'case', id: 'case-1'));
		$this->assertSame(CasePaymentState::PAID, $this->register->row(schema: 'case', id: 'case-2')['paymentState']);
	}//end testAStaleReadKeepsTheLastKnownState()

	/**
	 * An unchanged state is not written again, not even its timestamp.
	 *
	 * @return void
	 */
	public function testAnUnchangedStateIsNotWritten(): void {
		$this->states = ['case-1' => CasePaymentState::OUTSTANDING, 'case-2' => CasePaymentState::OUTSTANDING];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$this->runJobOnce(job: $this->job(logger: $logger));

		$this->assertSame(['case-1', 'case-2'], $this->asked);
		$this->assertSame([], $this->register->writes);
	}//end testAnUnchangedStateIsNotWritten()

	/**
	 * A written state is reported with how many cases changed.
	 *
	 * @return void
	 */
	public function testTheRefreshIsReportedWithItsCount(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'PaymentStateProjectionJob: refreshed the payment state of the open cases',
			['written' => 2]
		);

		$this->runJobOnce(job: $this->job(logger: $logger));
	}//end testTheRefreshIsReportedWithItsCount()

	/**
	 * A case whose patch fails is logged with its id; the other case is still written.
	 *
	 * @return void
	 */
	public function testOneFailingCaseDoesNotStopTheOthers(): void {
		$this->register = new class (actor: fn (): ?string => $this->actingUid()) extends AnonymousRefusingRegister {

			/**
			 * Patch a row, failing for case-1.
			 *
			 * @param string          $objectId      The id.
			 * @param array           $data          The changes.
			 * @param string|int|null $register      Ignored.
			 * @param string|int|null $schema        The schema.
			 * @param bool            $_rbac         Passed on.
			 * @param bool            $_multitenancy Passed on.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function patchObject(
				string $objectId,
				array $data,
				string|int|null $register = null,
				string|int|null $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($objectId === 'case-1') {
					throw new \RuntimeException('database is gone');
				}

				return parent::patchObject(
					objectId: $objectId,
					data: $data,
					register: $register,
					schema: $schema,
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);
			}//end patchObject()
		};
		$this->seedTwoCases();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'PaymentStateProjectionJob: one case could not be refreshed',
			['caseId' => 'case-1', 'exception' => 'database is gone']
		);
		$logger->expects($this->once())->method('info')->with($this->anything(), ['written' => 1]);

		$this->runJobOnce(job: $this->job(logger: $logger));

		$this->assertSame(CasePaymentState::OUTSTANDING, $this->register->row(schema: 'case', id: 'case-1')['paymentState']);
		$this->assertSame(CasePaymentState::PAID, $this->register->row(schema: 'case', id: 'case-2')['paymentState']);
	}//end testOneFailingCaseDoesNotStopTheOthers()

	/**
	 * Seed the two open cases.
	 *
	 * @return void
	 */
	private function seedTwoCases(): void {
		foreach (['case-1', 'case-2'] as $id) {
			$this->register->seed(
				schema: 'case',
				id: $id,
				row: ['title' => $id, 'isFinalStatus' => false, 'isDraft' => false, 'paymentState' => CasePaymentState::OUTSTANDING]
			);
		}
	}//end seedTwoCases()

	/**
	 * The real job, with the shillinq read doubled.
	 *
	 * @param bool                 $moneyApp   Whether the money app is installed.
	 * @param string               $caseSchema The configured case schema.
	 * @param LoggerInterface|null $logger     The logger, or a silent one.
	 *
	 * @return object The job.
	 */
	private function job(bool $moneyApp = true, string $caseSchema = 'case', ?LoggerInterface $logger = null): object {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($moneyApp);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => $caseSchema,
				default => $default,
			}
		);

		/** @var CasePaymentReader&MockObject $payments */
		$payments = $this->createMock(CasePaymentReader::class);
		$payments->method('stateOf')->willReturnCallback(
			function (string $caseId): array {
				$this->asked[] = $caseId;
				return [
					'paymentState' => $this->states[$caseId] ?? CasePaymentState::OUTSTANDING,
					'paymentStateCheckedAt' => '2026-10-07T10:00:00+00:00',
				];
			}
		);

		return $this->buildWith(
			PaymentStateProjectionJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'payments' => $payments,
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => $logger ?? $this->createMock(LoggerInterface::class),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
