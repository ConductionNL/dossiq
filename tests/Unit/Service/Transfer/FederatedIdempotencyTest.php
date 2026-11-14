<?php

/**
 * The idempotency check in front of a federated case transfer.
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

use OCA\Dossiq\Service\Transfer\FederatedIdempotency;
use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * When a key is made, and which earlier transfer answers for it.
 *
 * @covers \OCA\Dossiq\Service\Transfer\FederatedIdempotency
 */
class FederatedIdempotencyTest extends TestCase {

	private function idempotency(bool $federation = true): FederatedIdempotency {
		$gateway = $this->createMock(TransferRegisterGateway::class);
		$gateway->method('federationShareService')->willReturn($federation === true ? new \stdClass() : null);

		return new FederatedIdempotency($gateway);
	}

	private function objects(array|\Throwable $matches): object {
		return new class ($matches) {
			public array $queries = [];

			public function __construct(private array|\Throwable $matches) {
			}

			public function findAll(array $query): array {
				$this->queries[] = $query;
				if ($this->matches instanceof \Throwable) {
					throw $this->matches;
				}

				return $this->matches;
			}
		};
	}

	public function testALocalTransferNeedsNoKey(): void {
		$objects = $this->objects([]);

		$this->assertSame(['key' => null, 'answer' => null], $this->idempotency()->precheck(null, 'c-1', 'org', 1, 2, $objects));
		$this->assertSame(['key' => null, 'answer' => null], $this->idempotency()->precheck('', 'c-1', 'org', 1, 2, $objects));
		$this->assertSame([], $objects->queries);
	}

	public function testAFederatedTransferWithoutTheFederationLeafIsRefused(): void {
		$this->assertSame(
			['key' => null, 'answer' => ['error' => 'Federated case transfer requires the OpenRegister federation leaf']],
			$this->idempotency(federation: false)->precheck('bob@remote', 'c-1', 'org', 1, 2, $this->objects([]))
		);
	}

	public function testTheKeyHashesCaseTargetAndRemoteAndFindsNoEarlierTransfer(): void {
		$objects = $this->objects([]);
		$key     = hash('sha256', 'c-1|org-9|bob@remote');

		$this->assertSame(['key' => $key, 'answer' => null], $this->idempotency()->precheck('bob@remote', 'c-1', 'org-9', 3, 8, $objects));
		$this->assertSame([['filters' => ['register' => 3, 'schema' => 8, 'idempotencyKey' => $key]]], $objects->queries);
	}

	public function testAPendingOrAcceptedTransferAnswersForTheKey(): void {
		$accepted = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'tr-2', 'status' => 'accepted'];
			}
		};

		$answer = $this->idempotency()->findByKey('k', 1, 2, $this->objects([['id' => 'tr-0', 'status' => 'rejected'], $accepted]));
		$this->assertSame(['id' => 'tr-2', 'status' => 'accepted'], $answer);

		$this->assertSame(
			['id' => 'tr-1', 'status' => 'pending'],
			$this->idempotency()->findByKey('k', 1, 2, $this->objects([['id' => 'tr-1', 'status' => 'pending'], ['id' => 'tr-3', 'status' => 'accepted']]))
		);
	}

	public function testOnlyFinishedOrFailedTransfersMeansNoAnswer(): void {
		$this->assertNull($this->idempotency()->findByKey('k', 1, 2, $this->objects([['status' => 'rejected'], ['status' => 'cancelled'], ['id' => 'x']])));
	}

	public function testAFailingSearchMeansNoAnswer(): void {
		$this->assertNull($this->idempotency()->findByKey('k', 1, 2, $this->objects(new RuntimeException('db'))));
	}
}
