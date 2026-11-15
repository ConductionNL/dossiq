<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contact moment logged on a case, and what reaches pipelinq's record.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
class ContactMomentBridgeTest extends TestCase {

	/**
	 * Everything the pipelinq double was asked to append.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $appended = [];

	/**
	 * What the pipelinq double answers to a create.
	 *
	 * @var array<string, mixed>
	 */
	private array $createAnswer = ['status' => 201];

	/**
	 * What the pipelinq double answers to a list.
	 *
	 * @var array<string, mixed>
	 */
	private array $listAnswer = ['status' => 200, 'contactMoments' => [], 'indicators' => []];

	/**
	 * A bridge over a pipelinq double, or over nothing at all.
	 *
	 * @param bool $withPipelinq Whether pipelinq is installed.
	 *
	 * @return ContactMomentBridge The bridge under test.
	 */
	private function bridge(bool $withPipelinq = true): ContactMomentBridge {
		$leaf = new class($this->appended, $this->createAnswer, $this->listAnswer) {
			/**
			 * @param array<int, array<string, mixed>> $appended Captured payloads.
			 * @param array<string, mixed> $createAnswer What create answers.
			 * @param array<string, mixed> $listAnswer What list answers.
			 */
			public function __construct(
				public array &$appended,
				public array $createAnswer,
				public array $listAnswer,
			) {
			}

			/**
			 * @param string $hostId The host.
			 * @param array<string, mixed> $payload The payload.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function create(string $hostId, array $payload): array {
				$this->appended[] = ['hostId' => $hostId] + $payload;

				return $this->createAnswer;
			}

			/**
			 * @param string $hostId The host.
			 * @param string $partyId The party.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function list(string $hostId, string $partyId = ''): array {
				return $this->listAnswer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($leaf, $withPipelinq): object {
				if ($withPipelinq === false) {
					throw new RuntimeException('pipelinq is not installed');
				}

				if ($id === PipelinqGateway::CONTACT_MOMENTS) {
					return $leaf;
				}

				throw new RuntimeException("nothing answers to {$id}");
			}
		);

		return new ContactMomentBridge(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);
	}//end bridge()

	/**
	 * A logged call reaches pipelinq with its direction and its case.
	 *
	 * @return void
	 */
	public function testALoggedCallReachesPipelinq(): void {
		$bridge = $this->bridge();

		$result = $bridge->append(
			caseId: 'case-1',
			moment: [
				'notificationChannel' => 'phone',
				'direction' => 'inbound',
				'nature' => 'statusverzoek',
				'summary' => 'Gebeld over de doorlooptijd',
				'startTime' => '2026-09-18T10:00:00+00:00',
				'contact' => 'party-1',
			],
		);

		$this->assertTrue($result['appended']);
		$this->assertCount(1, $this->appended);
		$this->assertSame('case-1', $this->appended[0]['hostId']);
		$this->assertSame('inbound', $this->appended[0]['direction']);
		$this->assertSame(
			'telefoon',
			$this->appended[0]['channel'],
			'dossiq\'s channel word is mapped into the fleet\'s, or the facet cannot be filtered.'
		);
		$this->assertSame('statusverzoek', $this->appended[0]['title']);
		$this->assertSame('party-1', $this->appended[0]['client']);
	}//end testALoggedCallReachesPipelinq()

	/**
	 * A refusal does not lose the dossiq write, and is reported.
	 *
	 * @return void
	 */
	public function testARefusalDoesNotLoseTheDossiqWrite(): void {
		$this->createAnswer = [
			'status' => 409,
			'error' => 'Outbound contact with this party is blocked by Overleden.',
			'indicators' => [['code' => 'overleden', 'label' => 'Overleden']],
		];

		$result = $this->bridge()->append(
			caseId: 'case-1',
			moment: ['notificationChannel' => 'email', 'direction' => 'outbound', 'nature' => 'report'],
		);

		$this->assertFalse($result['appended']);
		$this->assertStringContainsString('Overleden', $result['reason']);
		$this->assertSame('overleden', $result['indicators'][0]['code']);
	}//end testARefusalDoesNotLoseTheDossiqWrite()

	/**
	 * Without pipelinq the append is a no-op that says why.
	 *
	 * @return void
	 */
	public function testWithoutPipelinqTheAppendIsANoOp(): void {
		$result = $this->bridge(withPipelinq: false)->append(
			caseId: 'case-1',
			moment: ['notificationChannel' => 'phone', 'direction' => 'inbound'],
		);

		$this->assertFalse($result['appended']);
		$this->assertSame([], $this->appended);
		$this->assertNotSame('', $result['reason']);
	}//end testWithoutPipelinqTheAppendIsANoOp()

	/**
	 * A case with no pipelinq answers unavailable, not empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function testAnAbsentPipelinqIsNotAnEmptyCase(): void {
		$panel = $this->bridge(withPipelinq: false)->onCase(caseId: 'case-1');

		$this->assertFalse(
			$panel['available'],
			'"pipelinq is not installed" and "this case has no contact moments" are different sentences.'
		);
		$this->assertSame([], $panel['moments']);
	}//end testAnAbsentPipelinqIsNotAnEmptyCase()

	/**
	 * A shared contact moment says so, counting what the reader may not see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function testASharedMomentSaysSo(): void {
		$this->listAnswer = [
			'status' => 200,
			'contactMoments' => [
				[
					'id' => 'cm-1',
					'subject' => 'Een telefoontje over drie zaken',
					'shared' => true,
					'alsoOnCases' => ['case-a'],
					'alsoOnHiddenCount' => 1,
				],
			],
			'indicators' => [],
		];

		$panel = $this->bridge()->onCase(caseId: 'case-b');

		$this->assertTrue($panel['available']);
		$this->assertCount(1, $panel['moments'], 'One record, not one per case.');
		$this->assertStringContainsString('1 other case', $panel['moments'][0]['sharedLabel']);
		$this->assertStringContainsString('you may not see', $panel['moments'][0]['sharedLabel']);
	}//end testASharedMomentSaysSo()

	/**
	 * A moment on one case only is not marked as shared.
	 *
	 * The control: a marker that said "also on" about everything would pass
	 * the test above.
	 *
	 * @return void
	 */
	public function testAMomentOnOneCaseIsNotMarked(): void {
		$bridge = $this->bridge();

		$this->assertSame('', $bridge->sharedLabel(moment: ['shared' => false]));
		$this->assertSame(
			'Also on 2 other cases',
			$bridge->sharedLabel(moment: ['shared' => true, 'alsoOnCases' => ['a', 'b'], 'alsoOnHiddenCount' => 0])
		);
	}//end testAMomentOnOneCaseIsNotMarked()

	/**
	 * Filing goes through pipelinq's acts, and dossiq writes no reference set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function testFilingGoesThroughPipelinqsActs(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$offenders = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = (string)file_get_contents($file->getPathname());
			if (str_contains($contents, 'caseReferences') === true
				|| str_contains($contents, 'primaryCaseReference') === true
			) {
				$offenders[] = substr($file->getPathname(), strlen($root));
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'The reference set is pipelinq\'s to write; dossiq asks for the act: ' . implode(', ', $offenders)
		);
	}//end testFilingGoesThroughPipelinqsActs()
}//end class
