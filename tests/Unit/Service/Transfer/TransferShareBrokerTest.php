<?php

/**
 * Minting and resolving the federated share that carries a case transfer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use OCA\Dossiq\Service\Transfer\TransferShareBroker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The share the broker asks for, and which shares it accepts back.
 *
 * @covers \OCA\Dossiq\Service\Transfer\TransferShareBroker
 */
class TransferShareBrokerTest extends TestCase {

	private function broker(?object $shareService = null, ?object $mapper = null, ?LoggerInterface $logger = null): TransferShareBroker {
		$gateway = $this->createMock(TransferRegisterGateway::class);
		$gateway->method('federationShareService')->willReturn($shareService);
		$gateway->method('federatedShareMapper')->willReturn($mapper);

		return new TransferShareBroker($gateway, $logger ?? $this->createMock(LoggerInterface::class));
	}

	private function share(string $direction = 'outgoing', string $status = 'active', string $permissions = 'read-write', string $uri = 'or://zaken/transfer/t-1'): object {
		return new class ($direction, $status, $permissions, $uri) {
			public function __construct(private string $direction, private string $status, private string $permissions, private string $uri) {
			}

			public function getDirection(): string {
				return $this->direction;
			}

			public function getStatus(): string {
				return $this->status;
			}

			public function getPermissions(): string {
				return $this->permissions;
			}

			public function getObjectUri(): string {
				return $this->uri;
			}

			public function getSharedWith(): string {
				return 'bob@remote.example';
			}

			public function getOrganisation(): string {
				return 'org-9';
			}
		};
	}

	private function mapper(?object $share): object {
		return new class ($share) {
			public array $tokens = [];

			public function __construct(private ?object $share) {
			}

			public function findByToken(string $token): object {
				$this->tokens[] = $token;
				if ($this->share === null) {
					throw new RuntimeException('not found');
				}

				return $this->share;
			}
		};
	}

	public function testAShareIsMintedForTheTransferObject(): void {
		$service = new class {
			public array $calls = [];

			public function createOutgoingShare(array $params): object {
				$this->calls[] = $params;
				return (object)['token' => 'tok'];
			}
		};

		$share = $this->broker(shareService: $service)->mintTransferShare('t-1', 'bob@remote.example', 'zaken', 'transfer');

		$this->assertEquals((object)['token' => 'tok'], $share);
		$this->assertSame(
			[['scope' => 'object', 'register' => 'zaken', 'schema' => 'transfer', 'objectUri' => 't-1', 'sharedWith' => 'bob@remote.example', 'permissions' => 'read-write']],
			$service->calls
		);
	}

	public function testWithoutFederationNothingIsMinted(): void {
		$this->assertNull($this->broker()->mintTransferShare('t-1', 'bob@remote.example', 'zaken', 'transfer'));
	}

	public function testAFailedMintIsLoggedAndGivesNull(): void {
		$service = new class {
			public function createOutgoingShare(array $params): object {
				throw new RuntimeException('remote down');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'CaseTransferService: OR createOutgoingShare failed',
			['transferUuid' => 't-1', 'exception' => 'remote down']
		);

		$this->assertNull($this->broker(shareService: $service, logger: $logger)->mintTransferShare('t-1', 'x@y', 'zaken', 'transfer'));
	}

	public function testAMatchingOutgoingReadWriteShareResolvesToItsRecipient(): void {
		$mapper = $this->mapper($this->share());

		$this->assertSame(
			['sharedWith' => 'bob@remote.example', 'organisation' => 'org-9'],
			$this->broker(mapper: $mapper)->resolveTransferShare('tok', 't-1')
		);
		$this->assertSame(['tok'], $mapper->tokens);
	}

	public function testATrailingSlashOnTheObjectUriStillMatches(): void {
		$this->assertNotNull($this->broker(mapper: $this->mapper($this->share(uri: 'or://zaken/transfer/t-1/')))->resolveTransferShare('tok', 't-1'));
	}

	public function testMissingInputOrMapperResolvesNothing(): void {
		$mapper = $this->mapper($this->share());

		$this->assertNull($this->broker()->resolveTransferShare('tok', 't-1'));
		$this->assertNull($this->broker(mapper: $mapper)->resolveTransferShare('', 't-1'));
		$this->assertNull($this->broker(mapper: $mapper)->resolveTransferShare('tok', ''));
		$this->assertSame([], $mapper->tokens);
	}

	public function testAnUnknownTokenResolvesNothing(): void {
		$this->assertNull($this->broker(mapper: $this->mapper(null))->resolveTransferShare('tok', 't-1'));
	}

	public function testEachRefusedShareResolvesNothing(): void {
		$refused = [
			'incoming'    => $this->share(direction: 'incoming'),
			'revoked'     => $this->share(status: 'revoked'),
			'declined'    => $this->share(status: 'declined'),
			'read only'   => $this->share(permissions: 'read'),
			'other case'  => $this->share(uri: 'or://zaken/transfer/t-2'),
		];
		foreach ($refused as $why => $share) {
			$this->assertNull($this->broker(mapper: $this->mapper($share))->resolveTransferShare('tok', 't-1'), $why);
		}
	}
}
