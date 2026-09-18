<?php

/**
 * Unit tests for reading a saved mail file as a message.
 *
 * The two arms that matter are the two ways this can lie. A parse that
 * succeeded files a message; an instance with no integriq must NOT look like
 * one, because a case that claims somebody read an attachment when nobody did
 * is worse than the unreadable attachment.
 *
 * The doubles use `onlyMethods`, never `addMethods`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\SavedMailImport;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that the file is read by integriq or kept with a reason.
 *
 * @covers \OCA\Dossiq\Service\Email\SavedMailImport
 */
class SavedMailImportTest extends TestCase {
	/**
	 * @var CaseEmailRepository|MockObject
	 */
	private $cases;

	/**
	 * @var IAppManager|MockObject
	 */
	private $appManager;

	/**
	 * @var ContainerInterface|MockObject
	 */
	private $container;

	/**
	 * Set up the collaborators with integriq present and a parser answering.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cases = $this->getMockBuilder(CaseEmailRepository::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordReceivedEmail'])
			->getMock();

		$this->appManager = $this->createMock(IAppManager::class);
		// FleetAppId resolves the ID first and only then asks whether it is
		// enabled; doubling only the second answers false at the first step.
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->parser());
	}//end setUp()

	/**
	 * A stand-in for integriq's MessageParser and its ParsedMessage.
	 *
	 * Written by hand rather than doubled, because the real classes are not on
	 * an instance without integriq and this test must run on one.
	 *
	 * @return object The parser.
	 */
	private function parser(): object {
		return new class {
			/**
			 * Parse one saved mail file.
			 *
			 * @param string $filename The file name.
			 * @param string $raw      The bytes.
			 *
			 * @return object The parsed message.
			 */
			public function parse(string $filename, string $raw): object {
				return new class {
					/**
					 * @return string The subject.
					 */
					public function getSubject(): string {
						return 'Bezwaar dakkapel';
					}

					/**
					 * @return string The sender.
					 */
					public function getFrom(): string {
						return 'a.burger@example.nl';
					}

					/**
					 * @return array<int, string> The recipients.
					 */
					public function getTo(): array {
						return ['zaken@gemeente.nl'];
					}

					/**
					 * @return string The body.
					 */
					public function getBodyText(): string {
						return 'De tekst van het bezwaar.';
					}

					/**
					 * @return string|null The moment.
					 */
					public function getReceivedAt(): ?string {
						return '2026-09-18T10:15:00+02:00';
					}
				};
			}
		};
	}//end parser()

	/**
	 * Build the subject under test.
	 *
	 * @return SavedMailImport
	 */
	private function import(): SavedMailImport {
		return new SavedMailImport(
			$this->cases,
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}//end import()

	/**
	 * A parsed message is filed with its subject, sender and date.
	 *
	 * @return void
	 */
	public function testAParsedMessageIsFiledWithItsSubjectSenderAndDate(): void {
		$this->cases->expects($this->once())
			->method('recordReceivedEmail')
			->with(
				'case-1',
				'a.burger@example.nl',
				'zaken@gemeente.nl',
				'Bezwaar dakkapel',
				'De tekst van het bezwaar.',
				''
			)
			->willReturn('msg-1');

		$outcome = $this->import()->import('case-1', 'bezwaar.msg', 'CFB-bytes');

		$this->assertSame(SavedMailImport::OUTCOME_IMPORTED, $outcome['outcome']);
		$this->assertSame('Bezwaar dakkapel', $outcome['subject']);
		$this->assertSame('a.burger@example.nl', $outcome['from']);
		$this->assertSame('2026-09-18T10:15:00+02:00', $outcome['receivedAt']);
	}//end testAParsedMessageIsFiledWithItsSubjectSenderAndDate()

	/**
	 * With no integriq the file is kept and the reason names the missing app.
	 *
	 * A MISSING APP MUST NOT LOOK LIKE A PARSE THAT SUCCEEDED: nothing is
	 * filed at all, so the case never claims somebody read the attachment.
	 *
	 * @return void
	 */
	public function testWithNoIntegriqTheFileIsKeptAndTheReasonIsRecorded(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$this->appManager = $appManager;

		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$outcome = $this->import()->import('case-1', 'bezwaar.eml', 'From: a@b\r\n\r\ntekst');

		$this->assertSame(SavedMailImport::OUTCOME_KEPT, $outcome['outcome']);
		$this->assertStringContainsString('Integriq is not installed', $outcome['reason']);
		$this->assertSame('', $outcome['subject']);
	}//end testWithNoIntegriqTheFileIsKeptAndTheReasonIsRecorded()

	/**
	 * An integriq with no mail reader is kept too, with its own sentence.
	 *
	 * Two different sentences, because they ask for two different things: one
	 * reader has to install integriq, the other has to upgrade it.
	 *
	 * @return void
	 */
	public function testAnIntegriqWithNoReaderIsKeptWithItsOwnSentence(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		$this->container = $container;

		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$outcome = $this->import()->import('case-1', 'bezwaar.eml', 'bytes');

		$this->assertSame(SavedMailImport::OUTCOME_KEPT, $outcome['outcome']);
		$this->assertStringContainsString('no mail reader', $outcome['reason']);
	}//end testAnIntegriqWithNoReaderIsKeptWithItsOwnSentence()

	/**
	 * A parser that throws keeps the file rather than filing an empty message.
	 *
	 * @return void
	 */
	public function testAParserThatThrowsKeepsTheFile(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(
			new class {
				/**
				 * Refuse to parse.
				 *
				 * @param string $filename The file name.
				 * @param string $raw      The bytes.
				 *
				 * @return object Never.
				 */
				public function parse(string $filename, string $raw): object {
					throw new \RuntimeException('not a message');
				}
			}
		);
		$this->container = $container;

		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$outcome = $this->import()->import('case-1', 'foto.jpg', 'JPEG-bytes');

		$this->assertSame(SavedMailImport::OUTCOME_KEPT, $outcome['outcome']);
		$this->assertStringContainsString('could not be read as a message', $outcome['reason']);
	}//end testAParserThatThrowsKeepsTheFile()

	/**
	 * An empty file is refused before integriq is asked anything.
	 *
	 * @return void
	 */
	public function testAnEmptyFileIsRefusedBeforeIntegriqIsAsked(): void {
		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$outcome = $this->import()->import('case-1', 'leeg.eml', '');

		$this->assertSame(SavedMailImport::OUTCOME_KEPT, $outcome['outcome']);
		$this->assertStringContainsString('empty', $outcome['reason']);
	}//end testAnEmptyFileIsRefusedBeforeIntegriqIsAsked()

	/**
	 * A message with no subject falls back to the file name.
	 *
	 * An untitled row in the case's message list is a row nobody can pick out
	 * of five others.
	 *
	 * @return void
	 */
	public function testAMessageWithNoSubjectIsTitledAfterItsFile(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(
			new class {
				/**
				 * Parse to a message with no subject.
				 *
				 * @param string $filename The file name.
				 * @param string $raw      The bytes.
				 *
				 * @return object The parsed message.
				 */
				public function parse(string $filename, string $raw): object {
					return new class {
						/**
						 * @return string The subject.
						 */
						public function getSubject(): string {
							return '';
						}
					};
				}
			}
		);
		$this->container = $container;

		$outcome = $this->import()->import('case-1', 'bezwaar.msg', 'bytes');

		$this->assertSame('bezwaar.msg', $outcome['subject']);
	}//end testAMessageWithNoSubjectIsTitledAfterItsFile()
}//end class
