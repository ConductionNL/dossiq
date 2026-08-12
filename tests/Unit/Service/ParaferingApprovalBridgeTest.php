<?php

/**
 * ParaferingApprovalBridge Unit Tests
 *
 * Verifies that the procest -> OpenRegister approval-workflow bridge maps route
 * steps to OpenRegister ApprovalChain steps, routes approve/reject through
 * OpenRegister's ApprovalService against the pending step, encodes app-specific
 * metadata into the comment field, and degrades gracefully when OpenRegister's
 * approval-workflow backend is unavailable.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ParaferingApprovalBridge;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ParaferingApprovalBridge.
 *
 * @covers \OCA\Procest\Service\ParaferingApprovalBridge
 */
class ParaferingApprovalBridgeTest extends TestCase {
	/**
	 * Mocked procest settings/OpenRegister bridge.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a fake OpenRegister ApprovalChain entity with a UUID.
	 *
	 * @param string $uuid The chain UUID to report.
	 *
	 * @return object The fake chain.
	 */
	private function fakeChain(string $uuid): object {
		return new class($uuid) {
			/**
			 * @param string $uuid Chain UUID.
			 */
			public function __construct(
				private string $uuid,
			) {
			}

			/**
			 * @return string The chain UUID.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end fakeChain()

	/**
	 * Build a fake OpenRegister ApprovalStep entity.
	 *
	 * @param int $id Step id.
	 * @param string $status Step status.
	 *
	 * @return object The fake step.
	 */
	private function fakeStep(int $id, string $status): object {
		return new class($id, $status) {
			/**
			 * @param int $id Step id.
			 * @param string $status Step status.
			 */
			public function __construct(
				private int $id,
				private string $status,
			) {
			}

			/**
			 * @return int Step id.
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string Step status.
			 */
			public function getStatus(): string {
				return $this->status;
			}
		};
	}//end fakeStep()

	/**
	 * When OpenRegister is unavailable the bridge reports not-available and
	 * chain creation returns null (legacy path governs).
	 *
	 * @return void
	 */
	public function testIsAvailableFalseWhenOpenRegisterMissing(): void {
		$this->settings->method('getApprovalService')->willReturn(null);
		$this->settings->method('getOpenRegisterClass')->willReturn(null);

		$bridge = new ParaferingApprovalBridge($this->settings, $this->logger);

		$this->assertFalse($bridge->isAvailable());
		$this->assertNull(
			$bridge->initializeChainForVoorstel('voorstel-1', 'Route', [['order' => 1, 'role' => 'team']])
		);
	}//end testIsAvailableFalseWhenOpenRegisterMissing()

	/**
	 * Chain creation maps route steps to OpenRegister steps, persists via the
	 * chain mapper, initialises the chain, and returns the chain UUID.
	 *
	 * @return void
	 */
	public function testInitializeChainCreatesOrChainAndReturnsUuid(): void {
		$captured = null;

		$chainMapper = new class($this->fakeChain('chain-uuid-9')) {
			/** @var object */
			public static $captured = null;

			/**
			 * @param object $chain Chain to return.
			 */
			public function __construct(
				private object $chain,
			) {
			}

			/**
			 * @param array<string, mixed> $data Chain data.
			 *
			 * @return object The created chain.
			 */
			public function createFromArray(array $data): object {
				self::$captured = $data;
				return $this->chain;
			}
		};

		$approvalService = new class {
			/** @var array<string, mixed> */
			public static $init = [];

			/**
			 * @param object $chain The chain.
			 * @param string $objectUuid The object UUID.
			 *
			 * @return array<int, mixed> Created steps.
			 */
			public function initializeChain(object $chain, string $objectUuid): array {
				self::$init = ['chain' => $chain, 'objectUuid' => $objectUuid];
				return [];
			}
		};

		$this->settings->method('getApprovalService')->willReturn($approvalService);
		$this->settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($chainMapper) {
				if (str_contains($class, 'ApprovalChainMapper') === true) {
					return $chainMapper;
				}

				// Step mapper presence is required for isAvailable().
				return new \stdClass();
			}
		);

		$bridge = new ParaferingApprovalBridge($this->settings, $this->logger);

		$steps = [
			['order' => 1, 'role' => 'teamleider', 'type' => 'parafering'],
			['order' => 2, 'role' => 'directeur', 'type' => 'accordering'],
			['order' => 3, 'role' => 'skipme', 'skipped' => true],
		];

		$uuid = $bridge->initializeChainForVoorstel('voorstel-abc', 'Collegeadvies', $steps);

		$this->assertSame('chain-uuid-9', $uuid);
		$this->assertSame('voorstel-abc', $approvalService::$init['objectUuid']);

		$mapped = $chainMapper::$captured;
		$this->assertSame('Collegeadvies', $mapped['name']);
		// Skipped step is filtered out; two steps remain.
		$this->assertCount(2, $mapped['steps']);
		$this->assertSame('teamleider', $mapped['steps'][0]['role']);
		$this->assertSame('directeur', $mapped['steps'][1]['role']);
	}//end testInitializeChainCreatesOrChainAndReturnsUuid()

	/**
	 * Approving the current step resolves the pending step and calls
	 * OpenRegister approveStep with a JSON metadata-in-comment payload.
	 *
	 * @return void
	 */
	public function testApproveCurrentStepDelegatesWithMetaComment(): void {
		$approvalService = new class {
			/** @var array<string, mixed> */
			public static $call = [];

			/**
			 * @param int $stepId Step id.
			 * @param string $userId User id.
			 * @param string $comment Comment.
			 *
			 * @return array<string, mixed> Result.
			 */
			public function approveStep(int $stepId, string $userId, string $comment): array {
				self::$call = ['stepId' => $stepId, 'userId' => $userId, 'comment' => $comment];
				return ['step' => $stepId];
			}
		};

		$stepMapper = new class($this->fakeStep(42, 'pending')) {
			/**
			 * @param object $step Step to return.
			 */
			public function __construct(
				private object $step,
			) {
			}

			/**
			 * @param string $objectUuid Object UUID.
			 *
			 * @return array<int, object> Steps.
			 */
			public function findByObjectUuid(string $objectUuid): array {
				return [$this->step];
			}
		};

		$this->settings->method('getApprovalService')->willReturn($approvalService);
		$this->settings->method('getOpenRegisterClass')->willReturn($stepMapper);

		$bridge = new ParaferingApprovalBridge($this->settings, $this->logger);

		$result = $bridge->approveCurrentStep(
			'voorstel-abc',
			'alice',
			'Akkoord',
			['action' => 'parafered', 'actorType' => 'delegate', 'onBehalfOf' => 'bob', 'mandate' => 'M-1']
		);

		$this->assertIsArray($result);
		$this->assertSame(42, $approvalService::$call['stepId']);
		$this->assertSame('alice', $approvalService::$call['userId']);

		$decoded = json_decode($approvalService::$call['comment'], true);
		$this->assertSame('Akkoord', $decoded['text']);
		$this->assertSame('delegate', $decoded['_meta']['actorType']);
		$this->assertSame('bob', $decoded['_meta']['onBehalfOf']);
		$this->assertSame('M-1', $decoded['_meta']['mandate']);
		$this->assertArrayNotHasKey('advice', $decoded['_meta']);
	}//end testApproveCurrentStepDelegatesWithMetaComment()

	/**
	 * Rejecting a step with no pending step raises a RuntimeException.
	 *
	 * @return void
	 */
	public function testRejectThrowsWhenNoPendingStep(): void {
		$approvalService = new class {
			/**
			 * @param int $stepId Step id.
			 * @param string $userId User id.
			 * @param string $comment Comment.
			 *
			 * @return array<string, mixed> Result.
			 */
			public function rejectStep(int $stepId, string $userId, string $comment): array {
				return [];
			}
		};

		$stepMapper = new class {
			/**
			 * @param string $objectUuid Object UUID.
			 *
			 * @return array<int, object> Steps (none pending).
			 */
			public function findByObjectUuid(string $objectUuid): array {
				return [];
			}
		};

		$this->settings->method('getApprovalService')->willReturn($approvalService);
		$this->settings->method('getOpenRegisterClass')->willReturn($stepMapper);

		$bridge = new ParaferingApprovalBridge($this->settings, $this->logger);

		$this->expectException(RuntimeException::class);
		$bridge->rejectCurrentStep('voorstel-abc', 'alice', 'Ontbreekt', ['action' => 'returned']);
	}//end testRejectThrowsWhenNoPendingStep()

	/**
	 * A plain comment with no meta is passed through unchanged (no JSON wrap).
	 *
	 * @return void
	 */
	public function testPlainCommentPassedThroughWhenNoMeta(): void {
		$approvalService = new class {
			/** @var string */
			public static $comment = '';

			/**
			 * @param int $stepId Step id.
			 * @param string $userId User id.
			 * @param string $comment Comment.
			 *
			 * @return array<string, mixed> Result.
			 */
			public function approveStep(int $stepId, string $userId, string $comment): array {
				self::$comment = $comment;
				return [];
			}
		};

		$stepMapper = new class($this->fakeStep(7, 'pending')) {
			/**
			 * @param object $step Step.
			 */
			public function __construct(
				private object $step,
			) {
			}

			/**
			 * @param string $objectUuid Object UUID.
			 *
			 * @return array<int, object> Steps.
			 */
			public function findByObjectUuid(string $objectUuid): array {
				return [$this->step];
			}
		};

		$this->settings->method('getApprovalService')->willReturn($approvalService);
		$this->settings->method('getOpenRegisterClass')->willReturn($stepMapper);

		$bridge = new ParaferingApprovalBridge($this->settings, $this->logger);
		$bridge->approveCurrentStep('voorstel-abc', 'alice', 'Akkoord', []);

		$this->assertSame('Akkoord', $approvalService::$comment);
	}//end testPlainCommentPassedThroughWhenNoMeta()
}//end class
