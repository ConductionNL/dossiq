<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Revoking and pausing a case access link go through OpenRegister's access
 * link service; a refusal or failure is logged and never reported as done.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\AccessLinkProjection;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Sharing\CaseAccessLinkService
 */
class CaseAccessLinkRevokeAndPauseTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A revoke OpenRegister accepts is done.
	 *
	 * @return void
	 */
	public function testARevokeIsPassedOn(): void {
		$links = $this->links(revoked: true);
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->service(links: $links)->revokeLink(linkId: 12, userId: 'els'));
		$this->assertSame([['revoke', 12, 'els']], $links->calls);
	}//end testARevokeIsPassedOn()

	/**
	 * A refused revoke is logged as a warning and not done.
	 *
	 * @return void
	 */
	public function testARefusedRevokeIsWarned(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'CaseAccessLinkService: OpenRegister refused the revoke, which it does unless the caller minted the link',
			['linkId' => 12, 'userId' => 'piet']
		);

		$this->assertFalse($this->service(links: $this->links(revoked: 0))->revokeLink(linkId: 12, userId: 'piet'));
	}//end testARefusedRevokeIsWarned()

	/**
	 * A failing revoke is logged as an error; without OpenRegister's service nothing is revoked.
	 *
	 * @return void
	 */
	public function testAFailingOrUnavailableRevoke(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'CaseAccessLinkService: could not revoke an access link',
			['linkId' => 12, 'exception' => 'db down']
		);

		$this->assertFalse($this->service(links: $this->links(fails: new RuntimeException('db down')))->revokeLink(linkId: 12, userId: 'els'));
		$this->assertFalse($this->service(links: null)->revokeLink(linkId: 12, userId: 'els'));
	}//end testAFailingOrUnavailableRevoke()

	/**
	 * Pausing answers the updated link; a non-array answer, a failure or no service answers null.
	 *
	 * @return void
	 */
	public function testPausing(): void {
		$links = $this->links(updated: ['id' => 12, 'disabled' => true]);
		$this->assertSame(['id' => 12, 'disabled' => true], $this->service(links: $links)->setPaused(linkId: 12, userId: 'els', paused: true));
		$this->assertSame([['setDisabled', 12, 'els', true]], $links->calls);

		$this->assertNull($this->service(links: $this->links(updated: 'ok'))->setPaused(linkId: 12, userId: 'els', paused: false));
		$this->assertNull($this->service(links: null)->setPaused(linkId: 12, userId: 'els', paused: false));

		$this->logger->expects($this->once())->method('error')->with(
			'CaseAccessLinkService: could not pause an access link',
			['linkId' => 12, 'exception' => 'locked']
		);
		$this->assertNull($this->service(links: $this->links(fails: new RuntimeException('locked')))->setPaused(linkId: 12, userId: 'els', paused: true));
	}//end testPausing()

	/**
	 * A file link needs a case and a file; without OpenRegister's service no link is minted.
	 *
	 * @return void
	 */
	public function testFileLinksNeedACaseAFileAndTheService(): void {
		$service = $this->service(links: null);

		$this->assertSame(['error' => 'A file link must name both the case and the file'], $service->mintFileLink(caseId: ' ', fileId: 'f-1', userId: 'els'));
		$this->assertSame(['error' => 'A file link must name both the case and the file'], $service->mintFileLink(caseId: 'case-1', fileId: '', userId: 'els'));
		$this->assertSame(
			['error' => 'This instance cannot publish links: OpenRegister does not offer them'],
			$service->mintFileLink(caseId: 'case-1', fileId: 'f-1', userId: 'els')
		);
	}//end testFileLinksNeedACaseAFileAndTheService()

	/**
	 * The service over the given access link service, or none.
	 *
	 * @param object|null $links OpenRegister's access link service.
	 *
	 * @return CaseAccessLinkService
	 */
	private function service(?object $links): CaseAccessLinkService {
		$gateway = $this->createMock(OpenRegisterSharingGateway::class);
		$gateway->method('accessLinkService')->willReturn($links);

		return new CaseAccessLinkService(gateway: $gateway, projection: $this->createMock(AccessLinkProjection::class), logger: $this->logger);
	}//end service()

	/**
	 * An access link service with fixed answers.
	 *
	 * @param mixed           $revoked What revoke answers.
	 * @param mixed           $updated What setDisabled answers.
	 * @param \Throwable|null $fails   What every call throws.
	 *
	 * @return object
	 */
	private function links(mixed $revoked = false, mixed $updated = null, ?\Throwable $fails = null): object {
		return new class($revoked, $updated, $fails) {
			/**
			 * The calls received.
			 *
			 * @var array<int,array>
			 */
			public array $calls = [];

			/**
			 * @param mixed           $revoked What revoke answers.
			 * @param mixed           $updated What setDisabled answers.
			 * @param \Throwable|null $fails   What every call throws.
			 */
			public function __construct(private mixed $revoked, private mixed $updated, private ?\Throwable $fails) {
			}

			/**
			 * @return mixed
			 */
			public function revoke(int $linkId, string $userId): mixed {
				$this->calls[] = ['revoke', $linkId, $userId];
				if ($this->fails !== null) {
					throw $this->fails;
				}

				return $this->revoked;
			}

			/**
			 * @return mixed
			 */
			public function setDisabled(int $linkId, string $userId, bool $disabled): mixed {
				$this->calls[] = ['setDisabled', $linkId, $userId, $disabled];
				if ($this->fails !== null) {
					throw $this->fails;
				}

				return $this->updated;
			}
		};
	}//end links()
}//end class
