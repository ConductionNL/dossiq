<?php

/**
 * Unit tests for what dossiq records when a letter does not go out.
 *
 * `BerichtenboxService::sendMessage()` used to write `status: sent` and an
 * external message id from whatever the adapter answered, and the adapter an
 * unconfigured instance had was the mock, which answers exactly that. So the
 * case read "message sent" for a letter nobody received, and the public
 * timeline told the citizen on the portal the same thing.
 *
 * These arms are what keep a refusal and a delivery apart, and they name what
 * is recorded rather than only that something was.
 *
 * The doubles use `onlyMethods`, never `addMethods`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that a refused letter is recorded as not sent, with the reason.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxService
 * @uses \OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Support\OwningCaseResolver
 * @uses \OCA\Dossiq\Service\Timeline\CaseTimeline
 * @uses   \OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal
 */
class BerichtenboxServiceRefusalTest extends TestCase {
	/**
	 * @var BerichtenboxAdapterInterface|MockObject
	 */
	private $adapter;

	/**
	 * @var CaseTimeline|MockObject
	 */
	private $timeline;

	/**
	 * The row the service handed the object service, for assertions.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * The timeline entry the service wrote, for assertions.
	 *
	 * @var array<string, mixed>
	 */
	private array $recorded = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->adapter = $this->createMock(BerichtenboxAdapterInterface::class);
		$this->timeline = $this->getMockBuilder(CaseTimeline::class)
			->disableOriginalConstructor()
			->onlyMethods(['record'])
			->getMock();

		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				$this->recorded = [
					'caseId' => $caseId,
					'message' => $message,
					'fields' => $fields,
					'visibility' => $visibility,
				];

				return 'entry-1';
			}
		);
	}//end setUp()

	/**
	 * An object service that records what it was asked to store.
	 *
	 * Built from the stub contract rather than from a hand-written double, so
	 * a method the real interface does not carry cannot be invented here.
	 *
	 * @return object The double.
	 */
	private function objectService(): object {
		// createMock, not onlyMethods: this is an INTERFACE, and `onlyMethods`
		// leaves the other 25 methods abstract, so the generated class cannot
		// be instantiated at all. The safety `onlyMethods` buys on a class is
		// already inherent here, because a double of an interface can only
		// carry methods the interface declares.
		$service = $this->createMock('OCA\\OpenRegister\\Contract\\ObjectServiceInterface');

		// The real signature takes twelve parameters after `$object`, and the
		// service calls it with NAMED arguments. A closure that spelled the
		// positional ones out would bind `$register` to the extend array and
		// TypeError, which is exactly what the first draft of this file did.
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): object {
				$this->saved = $object;

				// The real return type is ObjectEntityInterface, so the double
				// has to be one: a bare JsonSerializable is refused by the
				// mock's own return type and the failure reads as a test
				// problem rather than as the contract it is.
				$entity = $this->createMock('OCA\\OpenRegister\\Contract\\ObjectEntityInterface');
				$entity->method('jsonSerialize')->willReturn(array_merge($object, ['id' => 'message-1']));

				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * Build the subject under test.
	 *
	 * @return BerichtenboxService
	 */
	private function service(): BerichtenboxService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getConfigValue'])
			->getMock();
		$settings->method('getConfigValue')->willReturn('1');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService());

		return new BerichtenboxService(
			$settings,
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->getMockBuilder(OwningCaseResolver::class)
				->disableOriginalConstructor()
				->onlyMethods(['resolve'])
				->getMock(),
			$this->adapter,
			// A REAL journal wrapping the SAME CaseTimeline double. The
			// assertions below still watch `$this->timeline`, so what this test
			// observes did not move when the journal was split out of the
			// service: only the wiring line did.
			new BerichtenboxJournal($this->timeline)
		);
	}//end service()

	/**
	 * A tracked message id is recorded as sent, on the public timeline.
	 *
	 * Without this arm a service that refused everything would satisfy the
	 * refusal arms below.
	 *
	 * @return void
	 */
	public function testATrackedMessageIsRecordedAsSent(): void {
		$this->adapter->method('sendMessage')->willReturn(
			['messageId' => 'dp-4711', 'status' => 'sent', 'sentAt' => '2026-09-18T12:00:00+00:00']
		);

		$result = $this->service()->sendMessage('case-1', '123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertSame('sent', $this->saved['status']);
		$this->assertSame('dp-4711', $this->saved['externalMessageId']);
		$this->assertArrayNotHasKey('refused', $result);
		$this->assertSame(CaseTimeline::PUBLIC_ENTRY, $this->recorded['visibility']);
	}//end testATrackedMessageIsRecordedAsSent()

	/**
	 * A refusal is recorded as not sent, with integriq's own reason.
	 *
	 * @return void
	 */
	public function testARefusalIsNeverRecordedAsADelivery(): void {
		$this->adapter->method('sendMessage')->willReturn(
			[
				'status' => 'refused',
				'refused' => true,
				'code' => 'missing-certificate',
				'error' => 'No PKIoverheid certificate is configured.',
			]
		);

		$result = $this->service()->sendMessage('case-1', '123456782', 'Besluit', 'De tekst', 'BESLUIT');

		// WHAT IS STORED, not merely that something was: a status of `sent` or
		// an external id here is the whole defect this test exists for.
		$this->assertSame('refused', $this->saved['status']);
		$this->assertNull($this->saved['externalMessageId']);
		$this->assertNull($this->saved['sentAt']);
		$this->assertSame('No PKIoverheid certificate is configured.', $this->saved['lastError']);

		$this->assertTrue($result['refused']);
		$this->assertSame('No PKIoverheid certificate is configured.', $result['error']);
	}//end testARefusalIsNeverRecordedAsADelivery()

	/**
	 * A refusal's timeline entry is internal and names the reason.
	 *
	 * The public entry is what a citizen reads on the portal. Telling them we
	 * tried to write to them and failed is not what the portal is for, and
	 * telling them nothing while the case says a letter went out is worse.
	 *
	 * @return void
	 */
	public function testARefusalWritesAnInternalEntryNamingTheReason(): void {
		$this->adapter->method('sendMessage')->willReturn(
			['status' => 'refused', 'refused' => true, 'error' => 'Integriq is not installed.']
		);

		$this->service()->sendMessage('case-1', '123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertSame(CaseTimeline::INTERNAL, $this->recorded['visibility']);
		$this->assertStringContainsString('Integriq is not installed.', $this->recorded['message']);
		$this->assertSame('refused', $this->recorded['fields']['status']);
	}//end testARefusalWritesAnInternalEntryNamingTheReason()
}//end class
