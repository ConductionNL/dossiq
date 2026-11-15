<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What dossiq sends pipelinq for a contact moment, and how each of pipelinq's
 * answers is read back.
 *
 * @covers \OCA\Dossiq\Service\Pipelinq\ContactMomentBridge
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class ContactMomentBridgeBehaviourTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A bridge over a pipelinq that holds the given services.
	 *
	 * @param array<string, object> $services Keyed by class name.
	 */
	private function bridge(array $services = []): ContactMomentBridge {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $services[$id];
			}
		);

		return new ContactMomentBridge(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			$this->logger
		);
	}//end bridge()

	/**
	 * A contact moments leaf that records its calls and answers fixed values.
	 */
	private function leaf(mixed $create = null, mixed $list = null): object {
		return new class($create, $list) {
			/** @var list<array<string, mixed>> */
			public array $created = [];

			/** @var list<array<string, string>> */
			public array $listed = [];

			public function __construct(private mixed $create, private mixed $list) {
			}

			public function create(string $hostId, array $payload): mixed {
				$this->created[] = ['hostId' => $hostId, 'payload' => $payload];
				return $this->create;
			}

			public function list(string $hostId, string $partyId): mixed {
				$this->listed[] = ['hostId' => $hostId, 'partyId' => $partyId];
				return $this->list;
			}
		};
	}//end leaf()

	/**
	 * A filing service that records its calls and answers a fixed value.
	 */
	private function filing(mixed $answer): object {
		return new class($answer) {
			/** @var list<array<string, mixed>> */
			public array $calls = [];

			public function __construct(private mixed $answer) {
			}

			public function fileOnAlsoCase(string $momentId, string $caseId): mixed {
				$this->calls[] = ['fileOnAlsoCase', $momentId, $caseId];
				return $this->answer;
			}

			public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary): mixed {
				$this->calls[] = ['unfileFromCase', $momentId, $caseId, $newPrimary];
				return $this->answer;
			}
		};
	}//end filing()

	public function testABlankCaseIsNotSentToPipelinq(): void {
		$leaf = $this->leaf(create: ['status' => 201]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->append(caseId: "  \t", moment: []);

		$this->assertSame(
			['appended' => false, 'reason' => 'the contact moment names no case', 'indicators' => []],
			$outcome
		);
		$this->assertSame([], $leaf->created);
	}//end testABlankCaseIsNotSentToPipelinq()

	public function testTheMomentIsTranslatedIntoPipelinqsPayload(): void {
		$leaf = $this->leaf(create: ['status' => 201]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->append(
			caseId: ' case-1 ',
			moment: [
				'nature' => '  Klacht  ',
				'notificationChannel' => 'webformulier',
				'direction' => 'inbound',
				'summary' => 'Belde over de aanslag',
				'startTime' => '2026-01-02T10:00:00+00:00',
				'contact' => 'party-9',
			]
		);

		$this->assertSame(['appended' => true, 'reason' => '', 'indicators' => []], $outcome);
		$this->assertSame(
			[[
				'hostId' => 'case-1',
				'payload' => [
					'title' => 'Klacht',
					'channel' => 'web',
					'direction' => 'inbound',
					'summary' => 'Belde over de aanslag',
					'occurredAt' => '2026-01-02T10:00:00+00:00',
					'client' => 'party-9',
				],
			]],
			$leaf->created
		);
	}//end testTheMomentIsTranslatedIntoPipelinqsPayload()

	/**
	 * Channel names dossiq uses and the ones pipelinq expects.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function channels(): array {
		return [
			'phone' => ['phone', 'telefoon'],
			'email' => ['email', 'email'],
			'chat' => ['chat', 'chat'],
			'social media' => ['social_media', 'social'],
			'counter' => ['balie', 'balie'],
			'letter' => ['brief', 'brief'],
			'unknown passes through' => ['fax', 'fax'],
			'absent' => ['', ''],
		];
	}//end channels()

	/**
	 * @dataProvider channels
	 */
	public function testTheChannelIsMappedToPipelinqsName(string $dossiq, string $pipelinq): void {
		$leaf = $this->leaf(create: ['status' => 201]);
		$moment = [];
		if ($dossiq !== '') {
			$moment['notificationChannel'] = $dossiq;
		}

		$this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->append(caseId: 'c', moment: $moment);

		$this->assertSame($pipelinq, $leaf->created[0]['payload']['channel']);
	}//end testTheChannelIsMappedToPipelinqsName()

	public function testWithoutANatureTheTitleIsTheSummaryCappedAt120Characters(): void {
		$leaf = $this->leaf(create: ['status' => 201]);
		$summary = str_repeat('é', 130);

		$this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->append(
			caseId: 'c',
			moment: ['nature' => '   ', 'summary' => "  {$summary}  "]
		);

		$this->assertSame(str_repeat('é', 120), $leaf->created[0]['payload']['title']);
		$this->assertSame("  {$summary}  ", $leaf->created[0]['payload']['summary']);
	}//end testWithoutANatureTheTitleIsTheSummaryCappedAt120Characters()

	public function testWithoutNatureOrSummaryTheTitleIsGeneric(): void {
		$leaf = $this->leaf(create: ['status' => 201]);

		$this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->append(caseId: 'c', moment: ['summary' => ' ']);

		$this->assertSame('Contactmoment', $leaf->created[0]['payload']['title']);
		$this->assertSame('', $leaf->created[0]['payload']['direction']);
		$this->assertSame('', $leaf->created[0]['payload']['occurredAt']);
		$this->assertSame('', $leaf->created[0]['payload']['client']);
	}//end testWithoutNatureOrSummaryTheTitleIsGeneric()

	public function testAnAbsentPipelinqDoesNotAppend(): void {
		$outcome = $this->bridge()->append(caseId: 'c', moment: []);

		$this->assertFalse($outcome['appended']);
		$this->assertSame('pipelinq does not serve this capability on this instance', $outcome['reason']);
		$this->assertSame([], $outcome['indicators']);
	}//end testAnAbsentPipelinqDoesNotAppend()

	public function testAnUnusableAnswerIsNotAnAppend(): void {
		$this->logger->expects($this->never())->method('info');

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $this->leaf(create: 'created')])
			->append(caseId: 'c', moment: []);

		$this->assertSame(
			['appended' => false, 'reason' => 'pipelinq answered an unusable shape', 'indicators' => []],
			$outcome
		);
	}//end testAnUnusableAnswerIsNotAnAppend()

	public function testARefusalCarriesItsReasonAndIndicatorsAndIsLogged(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('refused there, so the two records disagree until somebody looks: blocked'),
				['case' => 'c-3', 'status' => 409]
			);

		$outcome = $this->bridge(
			[PipelinqGateway::CONTACT_MOMENTS => $this->leaf(create: ['status' => 409, 'error' => 'blocked', 'indicators' => ['overleden']])]
		)->append(caseId: 'c-3', moment: []);

		$this->assertSame(['appended' => false, 'reason' => 'blocked', 'indicators' => ['overleden']], $outcome);
	}//end testARefusalCarriesItsReasonAndIndicatorsAndIsLogged()

	public function testARefusalWithoutAnErrorGetsTheDefaultReason(): void {
		$this->logger->expects($this->once())->method('info')->with($this->anything(), ['case' => 'c', 'status' => 0]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $this->leaf(create: [])])
			->append(caseId: 'c', moment: []);

		$this->assertSame(['appended' => false, 'reason' => 'pipelinq refused the append', 'indicators' => []], $outcome);
	}//end testARefusalWithoutAnErrorGetsTheDefaultReason()

	public function testOnCaseListsTrimmedIdsAndLabelsEachMoment(): void {
		$leaf = $this->leaf(
			list: [
				'status' => 200,
				'contactMoments' => [
					['id' => 'm1', 'shared' => true, 'alsoOnCases' => ['x']],
					'not a moment',
					['id' => 'm2'],
				],
				'indicators' => ['geheim'],
			]
		);

		$result = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $leaf])->onCase(caseId: ' c1 ', partyId: ' p1 ');

		$this->assertSame(
			[
				'available' => true,
				'moments' => [
					['id' => 'm1', 'shared' => true, 'alsoOnCases' => ['x'], 'sharedLabel' => 'Also on 1 other case'],
					['id' => 'm2', 'sharedLabel' => ''],
				],
				'indicators' => ['geheim'],
			],
			$result
		);
		$this->assertSame([['hostId' => 'c1', 'partyId' => 'p1']], $leaf->listed);
	}//end testOnCaseListsTrimmedIdsAndLabelsEachMoment()

	public function testOnCaseWithoutMomentsIsAvailableAndEmpty(): void {
		$result = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $this->leaf(list: ['status' => 200])])->onCase(caseId: 'c');

		$this->assertSame(['available' => true, 'moments' => [], 'indicators' => []], $result);
	}//end testOnCaseWithoutMomentsIsAvailableAndEmpty()

	/**
	 * List answers that mean pipelinq could not be read.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unreadableLists(): array {
		return [
			'not an array' => ['nothing'],
			'no status' => [['contactMoments' => [['id' => 'm1']]]],
			'forbidden' => [['status' => 403, 'contactMoments' => [['id' => 'm1']]]],
		];
	}//end unreadableLists()

	/**
	 * @dataProvider unreadableLists
	 */
	public function testOnCaseIsUnavailableWhenTheListCannotBeRead(mixed $answer): void {
		$result = $this->bridge([PipelinqGateway::CONTACT_MOMENTS => $this->leaf(list: $answer)])->onCase(caseId: 'c');

		$this->assertSame(['available' => false, 'moments' => [], 'indicators' => []], $result);
	}//end testOnCaseIsUnavailableWhenTheListCannotBeRead()

	public function testOnCaseIsUnavailableWithoutPipelinq(): void {
		$this->assertSame(['available' => false, 'moments' => [], 'indicators' => []], $this->bridge()->onCase(caseId: 'c'));
	}//end testOnCaseIsUnavailableWithoutPipelinq()

	/**
	 * Moments and the sentence each one carries.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function sharedMoments(): array {
		return [
			'not shared' => [['alsoOnCases' => ['a', 'b']], ''],
			'shared as a truthy string' => [['shared' => 'yes', 'alsoOnCases' => ['a']], ''],
			'shared with nowhere else' => [['shared' => true], ''],
			'one named case' => [['shared' => true, 'alsoOnCases' => ['a']], 'Also on 1 other case'],
			'two named cases' => [['shared' => true, 'alsoOnCases' => ['a', 'b']], 'Also on 2 other cases'],
			'one hidden case' => [['shared' => true, 'alsoOnHiddenCount' => 1], 'Also on 1 case you may not see'],
			'three hidden cases' => [['shared' => true, 'alsoOnHiddenCount' => '3'], 'Also on 3 cases you may not see'],
			'named and hidden' => [
				['shared' => true, 'alsoOnCases' => ['a'], 'alsoOnHiddenCount' => 2],
				'Also on 1 other case and 2 you may not see',
			],
			'several named and hidden' => [
				['shared' => true, 'alsoOnCases' => ['a', 'b', 'c'], 'alsoOnHiddenCount' => 1],
				'Also on 3 other cases and 1 you may not see',
			],
		];
	}//end sharedMoments()

	/**
	 * @dataProvider sharedMoments
	 *
	 * @param array<string, mixed> $moment The moment.
	 */
	public function testTheSharedLabelSaysWhereElseTheMomentIs(array $moment, string $label): void {
		$this->assertSame($label, $this->bridge()->sharedLabel(moment: $moment));
	}//end testTheSharedLabelSaysWhereElseTheMomentIs()

	public function testFilingOnAFurtherCaseSendsTrimmedIds(): void {
		$filing = $this->filing(['status' => 200]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENT_FILING => $filing])
			->fileOnAlsoCase(momentId: ' m1 ', caseId: ' c2 ');

		$this->assertSame(['filed' => true, 'reason' => ''], $outcome);
		$this->assertSame([['fileOnAlsoCase', 'm1', 'c2']], $filing->calls);
	}//end testFilingOnAFurtherCaseSendsTrimmedIds()

	public function testUnfilingPassesTheNewPrimary(): void {
		$filing = $this->filing(['status' => 200]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENT_FILING => $filing])
			->unfileFromCase(momentId: 'm1', caseId: ' c2', newPrimary: 'c3');

		$this->assertSame(['filed' => true, 'reason' => ''], $outcome);
		$this->assertSame([['unfileFromCase', 'm1', 'c2', 'c3']], $filing->calls);
	}//end testUnfilingPassesTheNewPrimary()

	public function testARefusedUnfilingIsLoggedWithItsVerbAndStatus(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('a contact moment was not unfiled, and the refusal is what a handler reads: last case'),
				['status' => 422]
			);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENT_FILING => $this->filing(['status' => 422, 'error' => 'last case'])])
			->unfileFromCase(momentId: 'm1', caseId: 'c2');

		$this->assertSame(['filed' => false, 'reason' => 'last case'], $outcome);
	}//end testARefusedUnfilingIsLoggedWithItsVerbAndStatus()

	public function testARefusedFilingWithoutAnErrorGetsTheDefaultReason(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with($this->stringContains('was not filed'), ['status' => 500]);

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENT_FILING => $this->filing(['status' => 500])])
			->fileOnAlsoCase(momentId: 'm1', caseId: 'c2');

		$this->assertSame(['filed' => false, 'reason' => 'pipelinq refused the act'], $outcome);
	}//end testARefusedFilingWithoutAnErrorGetsTheDefaultReason()

	public function testAFilingAnswerThatIsNotAnArrayIsNotFiled(): void {
		$this->logger->expects($this->never())->method('info');

		$outcome = $this->bridge([PipelinqGateway::CONTACT_MOMENT_FILING => $this->filing(true)])
			->fileOnAlsoCase(momentId: 'm1', caseId: 'c2');

		$this->assertSame(['filed' => false, 'reason' => ''], $outcome);
	}//end testAFilingAnswerThatIsNotAnArrayIsNotFiled()

	public function testFilingWithoutPipelinqSaysWhy(): void {
		$outcome = $this->bridge()->fileOnAlsoCase(momentId: 'm1', caseId: 'c2');

		$this->assertSame(
			['filed' => false, 'reason' => 'pipelinq does not serve this capability on this instance'],
			$outcome
		);
	}//end testFilingWithoutPipelinqSaysWhy()
}//end class
