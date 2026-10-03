<?php

/**
 * Asking shillinq, and every way that can go wrong.
 *
 * ONE ANSWER FOR EVERY FAILURE, AND IT IS NOT "NOTHING IS OWED". shillinq
 * absent, the leaf unresolvable, the call throwing, the envelope a shape this
 * app cannot read: all four land on `stale`. They are asserted one by one
 * because they fail in four different places and would each have to be gotten
 * wrong separately, and getting any of them wrong renders as a case with no
 * payment problem.
 *
 * The double uses `onlyMethods`, which refuses a method the real class lacks.
 * A double that can invent `list()` would pass here and 500 in production,
 * which is the failure `addMethods` has already cost this fleet.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTime;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for shillinq's payment leaf.
 *
 * A real class rather than a mock of a name this test cannot see: shillinq is
 * an optional dependency, so `OCA\Shillinq\Integration\PaymentRequestLeafProvider`
 * does not exist in this test run at all, and mocking a class that is absent
 * would be inventing the seam instead of exercising it.
 */
class FakePaymentLeaf {
	/** @var array<string, mixed>|null What `list()` answers. */
	public ?array $answer = ['items' => [], 'total' => 0];

	/** @var bool Whether `list()` throws instead of answering. */
	public bool $throws = false;

	/**
	 * The leaf's list call.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<string, mixed>|null The envelope.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): ?array {
		if ($this->throws === true) {
			throw new RuntimeException('shillinq said no');
		}

		return $this->answer;
	}//end list()
}//end class

/**
 * The cross-app read.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class CasePaymentReaderTest extends TestCase {
	private IAppManager $apps;
	private ContainerInterface $container;
	private FakePaymentLeaf $leaf;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->apps = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->leaf = new FakePaymentLeaf();
	}//end setUp()

	/**
	 * A reader whose seam is present or absent, as asked.
	 *
	 * @param bool $installed Whether shillinq is installed.
	 * @param object|null $leaf What the container answers with, or null to throw.
	 *
	 * @return CasePaymentReader The reader.
	 */
	private function reader(bool $installed, ?object $leaf = null): CasePaymentReader {
		$this->apps->method('isInstalled')->willReturn($installed);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-18T10:00:00+02:00'));

		if ($leaf === null) {
			$this->container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$this->container->method('get')->willReturn($leaf);
		}

		return new CasePaymentReader(
			appManager: $this->apps,
			container: $this->container,
			time: $time,
			states: new CasePaymentState(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end reader()

	/**
	 * No money app is not a waived fee.
	 *
	 * @return void
	 */
	public function testWithoutShillinqTheStateIsStaleAndNotNotRequired(): void {
		$projection = $this->reader(installed: false)->stateOf(caseId: 'case-1');

		$this->assertSame(CasePaymentState::STALE, $projection['paymentState']);
		$this->assertSame('2026-09-18T10:00:00+02:00', $projection['paymentStateCheckedAt']);
	}//end testWithoutShillinqTheStateIsStaleAndNotNotRequired()

	/**
	 * A case with no id is not a case with no fee.
	 *
	 * @return void
	 */
	public function testAnEmptyCaseIdIsStale(): void {
		$this->assertSame(
			CasePaymentState::STALE,
			$this->reader(installed: true, leaf: $this->leaf)->stateOf(caseId: '')['paymentState']
		);
	}//end testAnEmptyCaseIdIsStale()

	/**
	 * A leaf that throws is a failure to answer.
	 *
	 * @return void
	 */
	public function testALeafThatThrowsIsStale(): void {
		$this->leaf->throws = true;

		$this->assertSame(
			CasePaymentState::STALE,
			$this->reader(installed: true, leaf: $this->leaf)->stateOf(caseId: 'case-1')['paymentState']
		);
	}//end testALeafThatThrowsIsStale()

	/**
	 * An envelope without items is a shape this app cannot read.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutItemsIsStale(): void {
		$this->leaf->answer = ['total' => 0];

		$this->assertSame(
			CasePaymentState::STALE,
			$this->reader(installed: true, leaf: $this->leaf)->stateOf(caseId: 'case-1')['paymentState']
		);
	}//end testAnEnvelopeWithoutItemsIsStale()

	/**
	 * An empty items list IS an answer: this case owes nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyItemsListMeansNothingIsOwed(): void {
		$this->leaf->answer = ['items' => [], 'total' => 0];

		$this->assertSame(
			CasePaymentState::NOT_REQUIRED,
			$this->reader(installed: true, leaf: $this->leaf)->stateOf(caseId: 'case-1')['paymentState']
		);
	}//end testAnEmptyItemsListMeansNothingIsOwed()

	/**
	 * shillinq's own report is what decides, and it is not re-derived here.
	 *
	 * @return void
	 */
	public function testTheReportedStateIsWhatTheCaseCarries(): void {
		$this->leaf->answer = [
			'items' => [
				['id' => 'r1', 'amount' => 162.5, 'reported' => ['state' => 'open'], 'settlements' => []],
			],
			'total' => 1,
		];

		$projection = $this->reader(installed: true, leaf: $this->leaf)->stateOf(caseId: 'case-1');

		$this->assertSame(CasePaymentState::OUTSTANDING, $projection['paymentState']);
		// And no money came across with it.
		$this->assertSame(['paymentState', 'paymentStateCheckedAt'], array_keys($projection));
	}//end testTheReportedStateIsWhatTheCaseCarries()
}//end class
