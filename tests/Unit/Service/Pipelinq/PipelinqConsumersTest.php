<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\People\PartyIndicatorReader;
use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PartyRefusalReader;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The kinds, the joined refusal, the language and the programme.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
 */
class PipelinqConsumersTest extends TestCase {

	/**
	 * The pipelinq services this instance has, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * A gateway over whatever `$this->services` holds.
	 *
	 * @return PipelinqGateway The gateway.
	 */
	private function gateway(): PipelinqGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $this->services[$id];
			}
		);

		return new PipelinqGateway($container, $this->createMock(LoggerInterface::class));
	}//end gateway()

	/**
	 * The shipped vocabulary, with its labels untranslated.
	 *
	 * @return PartyVocabulary The vocabulary.
	 */
	private function vocabulary(): PartyVocabulary {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PartyVocabulary($l10n);
	}//end vocabulary()

	/**
	 * Dossiq's own kinds answer when pipelinq does not, and say so.
	 *
	 * @return void
	 */
	public function testTheShippedKindsAreTheFallback(): void {
		$consumer = new PartyKindConsumer(
			$this->gateway(),
			$this->vocabulary(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

		$answer = $consumer->kindsFor(caseType: 'subsidie');

		$this->assertSame('dossiq', $answer['source'], 'A picker has to be able to say where its list came from.');
		$this->assertSame(
			['person', 'organisation', 'address'],
			array_column($answer['kinds'], 'key'),
			'An empty picker reads as "this case type accepts no party at all".'
		);
	}//end testTheShippedKindsAreTheFallback()

	/**
	 * pipelinq's kinds answer when pipelinq is there, and are not merged.
	 *
	 * @return void
	 */
	public function testPipelinqsKindsWinWhenPipelinqAnswers(): void {
		$asked = '';
		$this->services[PipelinqGateway::PARTY_KINDS] = new class($asked) {
			/**
			 * @param string $asked Captures the record type.
			 */
			public function __construct(public string &$asked) {
			}

			/**
			 * @param string $recordType The target.
			 *
			 * @return array<int, array<string, mixed>> The kinds.
			 */
			public function kindsFor(string $recordType): array {
				$this->asked = $recordType;

				return [['code' => 'aanvrager'], ['code' => 'gemachtigde']];
			}
		};

		$consumer = new PartyKindConsumer(
			$this->gateway(),
			$this->vocabulary(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

		$answer = $consumer->kindsFor(caseType: 'subsidie');

		$this->assertSame('pipelinq', $answer['source']);
		$this->assertSame(['aanvrager', 'gemachtigde'], array_column($answer['kinds'], 'code'));
		$this->assertSame(
			'dossiq:case:subsidie',
			$asked,
			'The target is built here and nowhere else, so the two halves cannot drift.'
		);
	}//end testPipelinqsKindsWinWhenPipelinqAnswers()

	/**
	 * An empty answer from pipelinq is pipelinq's, not a reason to fall back.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsStillPipelinqs(): void {
		$this->services[PipelinqGateway::PARTY_KINDS] = new class {
			/**
			 * @param string $recordType The target.
			 *
			 * @return array<int, mixed> Nothing.
			 */
			public function kindsFor(string $recordType): array {
				return [];
			}
		};

		$consumer = new PartyKindConsumer(
			$this->gateway(),
			$this->vocabulary(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame('pipelinq', $consumer->kindsFor(caseType: 'woo')['source']);
	}//end testAnEmptyAnswerIsStillPipelinqs()

	/**
	 * Either source refusing stops the act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
	 */
	public function testEitherSourceRefusing(): void {
		// OpenRegister refuses, pipelinq is absent.
		$openRegister = $this->createMock(PartyIndicatorReader::class);
		$openRegister->method('sendRefusalFor')->willReturn('Geheimhouding');

		$reader = new PartyRefusalReader($this->gateway(), $openRegister);
		$verdict = $reader->maySendTo(partyUuid: 'party-1');

		$this->assertTrue($verdict['refused']);
		$this->assertSame(['openregister'], $verdict['sources']);
		$this->assertSame('Geheimhouding', $verdict['indicator']);

		// pipelinq refuses, OpenRegister is silent.
		$silent = $this->createMock(PartyIndicatorReader::class);
		$silent->method('sendRefusalFor')->willReturn(null);

		$this->services[PipelinqGateway::PARTY_INDICATORS] = new class {
			/**
			 * @param string $partyId The party.
			 * @param string $act The act.
			 *
			 * @return array<string, mixed> The verdict.
			 */
			public function isBlocked(string $partyId, string $act): array {
				return [
					'blocked' => true,
					'act' => $act,
					'indicators' => [['code' => 'overleden', 'label' => 'Overleden']],
				];
			}

			/**
			 * @param string $partyId The party.
			 *
			 * @return array<int, mixed> The indicators.
			 */
			public function resolve(string $partyId): array {
				return [];
			}
		};

		$second = new PartyRefusalReader($this->gateway(), $silent);
		$fromPipelinq = $second->maySendTo(partyUuid: 'party-1');

		$this->assertTrue($fromPipelinq['refused']);
		$this->assertSame(['pipelinq'], $fromPipelinq['sources']);
		$this->assertSame('Overleden', $fromPipelinq['indicator']);
	}//end testEitherSourceRefusing()

	/**
	 * Both silent means the act goes ahead.
	 *
	 * The control: a reader that refused everything would pass the test above.
	 *
	 * @return void
	 */
	public function testBothSilentMeansAllowed(): void {
		$silent = $this->createMock(PartyIndicatorReader::class);
		$silent->method('sendRefusalFor')->willReturn(null);

		$verdict = (new PartyRefusalReader($this->gateway(), $silent))->maySendTo(partyUuid: 'party-1');

		$this->assertFalse($verdict['refused']);
		$this->assertSame([], $verdict['sources']);
	}//end testBothSilentMeansAllowed()

	/**
	 * pipelinq refusing the send is read as a send refusal.
	 *
	 * @return void
	 */
	public function testPipelinqRefusesTheSend(): void {
		$this->services[PipelinqGateway::PARTY_INDICATORS] = new class {
			/**
			 * @param string $partyId The party.
			 * @param string $act The act.
			 *
			 * @return array<string, mixed> Blocked for a send only.
			 */
			public function isBlocked(string $partyId, string $act): array {
				return [
					'blocked' => ($act === PartyRefusalReader::ACT_SEND),
					'act' => $act,
					'indicators' => [['code' => 'overleden', 'label' => 'Overleden']],
				];
			}

			/**
			 * @param string $partyId The party.
			 *
			 * @return array<int, mixed> The indicators.
			 */
			public function resolve(string $partyId): array {
				return [];
			}
		};

		$silent = $this->createMock(PartyIndicatorReader::class);
		$silent->method('sendRefusalFor')->willReturn(null);
		$silent->method('publicationRefusal')->willReturn(null);

		$reader = new PartyRefusalReader($this->gateway(), $silent);

		$this->assertTrue($reader->maySendTo(partyUuid: 'party-1')['refused']);
		$this->assertFalse(
			$reader->mayPublish(caseId: 'case-1', partyUuids: ['party-1'])['refused'],
			'Publishing is a separate question, and this indicator does not answer it.'
		);
	}//end testPipelinqRefusesTheSend()

	/**
	 * An unset preference is said, not dressed up as a choice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	public function testAnUnsetPreferenceIsSaid(): void {
		$this->services[PipelinqGateway::CORRESPONDENCE_LANGUAGE] = new class {
			/**
			 * @param string $partyId The party.
			 *
			 * @return array<string, mixed> The resolver's answer.
			 */
			public function resolve(string $partyId): array {
				return [
					'status' => 200,
					'language' => 'nl',
					'rule' => 'instanceDefault',
					'partyPreference' => '',
				];
			}
		};

		$consumer = new CorrespondenceLanguageConsumer($this->gateway());
		$answer = $consumer->forParty(partyId: 'party-1');

		$this->assertTrue($answer['available']);
		$this->assertSame('nl', $answer['language']);
		$this->assertFalse($answer['stated']);
		$this->assertStringContainsString('No preference is recorded', $answer['sentence']);
		$this->assertStringContainsString("instance's default", $answer['sentence']);
	}//end testAnUnsetPreferenceIsSaid()

	/**
	 * A stated preference reads as a choice.
	 *
	 * @return void
	 */
	public function testAStatedPreferenceReadsAsAChoice(): void {
		$this->services[PipelinqGateway::CORRESPONDENCE_LANGUAGE] = new class {
			/**
			 * @param string $partyId The party.
			 *
			 * @return array<string, mixed> The resolver's answer.
			 */
			public function resolve(string $partyId): array {
				return ['status' => 200, 'language' => 'en', 'rule' => 'party', 'partyPreference' => 'en'];
			}
		};

		$answer = (new CorrespondenceLanguageConsumer($this->gateway()))->forParty(partyId: 'party-1');

		$this->assertTrue($answer['stated']);
		$this->assertStringContainsString('asked to be written to in en', $answer['sentence']);
	}//end testAStatedPreferenceReadsAsAChoice()

	/**
	 * Nobody reads the property directly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	public function testNoCallerReadsThePropertyDirectly(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$offenders = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			if (str_ends_with($path, 'Pipelinq/CorrespondenceLanguageConsumer.php') === true) {
				continue;
			}

			if (str_contains((string)file_get_contents($path), 'correspondenceLanguage') === true) {
				$offenders[] = substr($path, strlen($root));
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'A caller reading the property has to reimplement three rules and gets the unset case wrong: '
			. implode(', ', $offenders)
		);
	}//end testNoCallerReadsThePropertyDirectly()

	/**
	 * A case already in a programme is refused, with the holder named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function testACaseAlreadyInAProgrammeIsRefused(): void {
		$linked = [];
		$this->services[PipelinqGateway::PROGRAMMES] = new class($linked) {
			/**
			 * @param array<int, array<string, mixed>> $linked Captured links.
			 */
			public function __construct(public array &$linked) {
			}

			/**
			 * @param string $programmeId The programme.
			 * @param string $domainObjectType The `<app>:<schema>` literal.
			 * @param string $domainObjectRef The object.
			 * @param string $title Its title.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function linkWork(
				string $programmeId,
				string $domainObjectType,
				string $domainObjectRef,
				string $title = '',
			): array {
				$this->linked[] = ['type' => $domainObjectType, 'ref' => $domainObjectRef];

				return ['status' => 409, 'error' => 'This work is already in programme programme-a.'];
			}

			/**
			 * @param array<string, mixed> $programme The programme.
			 * @param array<int, mixed> $tasks Its tasks.
			 *
			 * @return array<string, mixed> The figure.
			 */
			public function progressFor(array $programme, array $tasks = []): array {
				return [
					'mode' => 'fromEffort',
					'progress' => null,
					'computable' => false,
					'reason' => 'Hours cannot be read on this instance, so progress cannot be computed.',
				];
			}
		};

		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));

		$result = $consumer->linkCase(programmeId: 'programme-b', caseId: 'case-1', title: 'Bezwaar');

		$this->assertFalse($result['linked']);
		$this->assertStringContainsString('programme-a', $result['reason']);
		$this->assertSame('dossiq:case', $linked[0]['type'], 'A case names itself as dossiq:case and nothing else.');
	}//end testACaseAlreadyInAProgrammeIsRefused()

	/**
	 * An uncomputable progress is said, not shown as zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	public function testAnUncomputableProgressIsSaid(): void {
		$this->services[PipelinqGateway::PROGRAMMES] = new class {
			/**
			 * @param array<string, mixed> $programme The programme.
			 * @param array<int, mixed> $tasks Its tasks.
			 *
			 * @return array<string, mixed> The figure.
			 */
			public function progressFor(array $programme, array $tasks = []): array {
				return [
					'mode' => 'fromEffort',
					'progress' => null,
					'computable' => false,
					'reason' => 'Hours cannot be read on this instance, so progress cannot be computed.',
				];
			}
		};

		$figure = (new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class)))
			->progressOf(programmeId: 'programme-1');

		$this->assertTrue($figure['available']);
		$this->assertFalse($figure['computable']);
		$this->assertNull($figure['progress'], 'Zero would read as "nothing has been done".');
		$this->assertStringContainsString('cannot be computed', $figure['sentence']);
	}//end testAnUncomputableProgressIsSaid()

	/**
	 * A computable progress carries the mode that produced it.
	 *
	 * @return void
	 */
	public function testAComputableProgressNamesItsMode(): void {
		$this->services[PipelinqGateway::PROGRAMMES] = new class {
			/**
			 * @param array<string, mixed> $programme The programme.
			 * @param array<int, mixed> $tasks Its tasks.
			 *
			 * @return array<string, mixed> The figure.
			 */
			public function progressFor(array $programme, array $tasks = []): array {
				return ['mode' => 'fromTasks', 'progress' => 75, 'computable' => true, 'reason' => ''];
			}
		};

		$figure = (new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class)))
			->progressOf(programmeId: 'programme-1');

		$this->assertSame(75, $figure['progress']);
		$this->assertSame('fromTasks', $figure['mode']);
		$this->assertStringContainsString('derived from the tasks', $figure['sentence']);
	}//end testAComputableProgressNamesItsMode()

	/**
	 * A closing case hands off, and dossiq decides nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-closing-case-asks-pipelinq-for-the-satisfaction-survey-and-holds-no-survey-engine-req-plq-07
	 */
	public function testAClosingCaseHandsOff(): void {
		$told = [];
		$this->services[PipelinqGateway::SURVEY_DISPATCH] = new class($told) {
			/**
			 * @param array<int, array<string, mixed>> $told What it was told.
			 */
			public function __construct(public array &$told) {
			}

			/**
			 * @param string $entityType The type.
			 * @param array<string, mixed> $entity The record.
			 * @param array<string, mixed> $contact The recipient.
			 *
			 * @return array<int, mixed> The invitations pipelinq wrote.
			 */
			public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
				$this->told[] = ['type' => $entityType, 'status' => ($entity['status'] ?? '')];

				return [];
			}
		};

		$result = (new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class)))
			->caseCompleted(case: ['id' => 'case-1', 'status' => 'closed'], party: ['contactsUid' => 'c1']);

		$this->assertTrue($result['handedOff']);
		$this->assertSame('case', $told[0]['type']);
		$this->assertSame('closed', $told[0]['status']);
	}//end testAClosingCaseHandsOff()

	/**
	 * The hand-off never blocks the save.
	 *
	 * @return void
	 */
	public function testTheHandoffNeverBlocksTheSave(): void {
		$this->services[PipelinqGateway::SURVEY_DISPATCH] = new class {
			/**
			 * @param string $entityType The type.
			 * @param array<string, mixed> $entity The record.
			 * @param array<string, mixed> $contact The recipient.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
				throw new RuntimeException('pipelinq fell over mid-dispatch');
			}
		};

		$result = (new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class)))
			->caseCompleted(case: ['id' => 'case-1', 'status' => 'closed']);

		$this->assertFalse($result['handedOff'], 'It says so rather than throwing into the case save.');
	}//end testTheHandoffNeverBlocksTheSave()

	/**
	 * Dossiq holds no survey engine of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-closing-case-asks-pipelinq-for-the-satisfaction-survey-and-holds-no-survey-engine-req-plq-07
	 */
	public function testDossiqHoldsNoSurveyEngine(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$offenders = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = (string)file_get_contents($file->getPathname());
			if (str_contains($contents, 'surveyInvitation') === true
				|| str_contains($contents, 'surveyOptOut') === true
			) {
				$offenders[] = substr($file->getPathname(), strlen($root));
			}
		}

		$this->assertSame([], $offenders, 'The survey, its token and its opt-out are pipelinq\'s.');
	}//end testDossiqHoldsNoSurveyEngine()
}//end class
