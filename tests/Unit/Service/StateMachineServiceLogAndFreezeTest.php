<?php

/**
 * StateMachineService transition log and freeze Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Persisting a beschikking's transition log, and refusing edits and deletes once it is frozen.
 *
 * @covers \OCA\Dossiq\Service\StateMachineService
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class StateMachineServiceLogAndFreezeTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return StateMachineService The service.
	 */
	private function service(): StateMachineService {
		return new StateMachineService(settingsService: $this->settings, logger: $this->logger);
	}//end service()

	/**
	 * Configure storage with the given log schema.
	 *
	 * @param object $store  The object store.
	 * @param string $schema The log schema.
	 *
	 * @return void
	 */
	private function configure(object $store, string $schema): void {
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['state_machine_log_schema', '', $schema],
			]
		);
	}//end configure()

	/**
	 * Without a log schema the transition is not logged and a warning says why.
	 *
	 * @return void
	 */
	public function testLogWithoutSchemaWarnsAndWritesNothing(): void {
		$store = new InMemoryRegister();
		$this->configure(store: $store, schema: '');
		$this->logger->expects($this->once())->method('warning')->with('StateMachineService: log schema not configured');

		$this->assertSame([], $this->service()->logTransition(decisionId: 'd1', from: 'draft', to: 'approved-mandate'));
		$this->assertSame(0, $store->writes);
	}//end testLogWithoutSchemaWarnsAndWritesNothing()

	/**
	 * A transition is written with its from/to, a timestamp and the given actor and evidence.
	 *
	 * @return void
	 */
	public function testLogWritesTheTransitionRecord(): void {
		$store = new InMemoryRegister();
		$this->configure(store: $store, schema: 'transitionLog');

		$saved = $this->service()->logTransition(
			decisionId: 'd1',
			from: 'approved-mandate',
			to: 'signed',
			metadata: ['actor' => 'alice', 'actorType' => 'medewerker', 'trigger' => 'manual', 'evidenceMaterial' => ['doc-1']]
		);

		$this->assertSame(1, $store->writes);
		$this->assertSame('d1', $saved['decisionId']);
		$this->assertSame('generated-1', $saved['id']);
		$transition = $saved['overgang'];
		$this->assertSame('approved-mandate', $transition['van']);
		$this->assertSame('signed', $transition['to']);
		$this->assertSame('alice', $transition['actor']);
		$this->assertSame('medewerker', $transition['actorType']);
		$this->assertSame('manual', $transition['trigger']);
		$this->assertSame(['doc-1'], $transition['evidenceMaterial']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $transition['moment']));
		$this->assertSame($saved, $store->all('transitionLog')[0]);
	}//end testLogWritesTheTransitionRecord()

	/**
	 * Without metadata the system is the actor and the trigger is automatic.
	 *
	 * @return void
	 */
	public function testLogDefaultsToTheSystemActor(): void {
		$store = new InMemoryRegister();
		$this->configure(store: $store, schema: 'transitionLog');

		$transition = $this->service()->logTransition(decisionId: 'd2', from: 'signed', to: 'sent')['overgang'];

		$this->assertSame('systeem', $transition['actor']);
		$this->assertSame('systeem', $transition['actorType']);
		$this->assertSame('automatic', $transition['trigger']);
		$this->assertNull($transition['evidenceMaterial']);
	}//end testLogDefaultsToTheSystemActor()

	/**
	 * A failed write is logged as an error and answers an empty record instead of throwing.
	 *
	 * @return void
	 */
	public function testFailedWriteIsLoggedAndSwallowed(): void {
		$store = new InMemoryRegister();
		$store->refusedSchemas = ['transitionLog'];
		$this->configure(store: $store, schema: 'transitionLog');
		$this->logger->expects($this->once())->method('error')->with(
			'StateMachineService: failed to persist transition log',
			['exception' => 'Save refused for transitionLog', 'decisionId' => 'd3']
		);

		$this->assertSame([], $this->service()->logTransition(decisionId: 'd3', from: 'sent', to: 'archived'));
	}//end testFailedWriteIsLoggedAndSwallowed()

	/**
	 * A draft beschikking accepts content edits.
	 *
	 * @return void
	 */
	public function testDraftAcceptsContentEdits(): void {
		$this->service()->assertMutable(stored: ['currentStatus' => 'draft', 'rationale' => 'a'], changed: ['rationale' => 'b']);
		$this->service()->assertDeletable(stored: ['currentStatus' => 'approved-mandate']);
		$this->service()->assertDeletable(stored: []);

		$this->addToAssertionCount(3);
	}//end testDraftAcceptsContentEdits()

	/**
	 * A signed beschikking refuses a content edit.
	 *
	 * @return void
	 */
	public function testSignedRefusesContentEdits(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(StateMachineService::IMMUTABLE_ERROR);

		$this->service()->assertMutable(stored: ['currentStatus' => 'signed'], changed: ['rationale' => 'changed']);
	}//end testSignedRefusesContentEdits()

	/**
	 * A signed beschikking still accepts a change to a non-content field.
	 *
	 * @return void
	 */
	public function testSignedAcceptsNonContentFields(): void {
		$this->service()->assertMutable(stored: ['currentStatus' => 'sent'], changed: ['currentStatus' => 'archived', 'notes' => 'x']);

		$this->addToAssertionCount(1);
	}//end testSignedAcceptsNonContentFields()

	/**
	 * A write-once field may be set once and re-sent unchanged, but not changed, even on a draft.
	 *
	 * @return void
	 */
	public function testWriteOnceFieldCannotChange(): void {
		$service = $this->service();
		$service->assertMutable(stored: ['currentStatus' => 'draft'], changed: ['supersededBy' => 'd9']);
		$service->assertMutable(stored: ['currentStatus' => 'draft', 'supersededBy' => 'd9'], changed: ['supersededBy' => 'd9']);
		$this->addToAssertionCount(2);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(StateMachineService::IMMUTABLE_ERROR);
		$service->assertMutable(stored: ['currentStatus' => 'draft', 'supersededBy' => 'd9'], changed: ['supersededBy' => 'd8']);
	}//end testWriteOnceFieldCannotChange()

	/**
	 * A sent beschikking cannot be deleted.
	 *
	 * @return void
	 */
	public function testFrozenCannotBeDeleted(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(StateMachineService::IMMUTABLE_ERROR);

		$this->service()->assertDeletable(stored: ['currentStatus' => 'sent']);
	}//end testFrozenCannotBeDeleted()
}//end class
