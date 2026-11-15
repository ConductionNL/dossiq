<?php

/**
 * SavedMailImport over a parsed message whose parts are missing, blank or not plain values.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
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
 * @covers \OCA\Dossiq\Service\Email\SavedMailImport
 * @uses   \OCA\Dossiq\Service\Email\SavedMailFile
 * @uses   \OCA\Dossiq\Support\FleetAppId
 */
class SavedMailImportOddPartsTest extends TestCase {

	/**
	 * The case repository.
	 *
	 * @var CaseEmailRepository&MockObject
	 */
	private CaseEmailRepository $cases;

	/**
	 * Set up the case repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cases = $this->getMockBuilder(CaseEmailRepository::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordReceivedEmail'])
			->getMock();
	}//end setUp()

	/**
	 * The import over a parser that answers the given parsed message.
	 *
	 * @param object $parsed The parsed message.
	 *
	 * @return SavedMailImport
	 */
	private function importAnswering(object $parsed): SavedMailImport {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		$parser = new class ($parsed) {
			/**
			 * Construct.
			 *
			 * @param object $parsed The message to answer.
			 */
			public function __construct(private readonly object $parsed) {
			}//end __construct()

			/**
			 * Answer the message.
			 *
			 * @param string $filename The file name.
			 * @param string $raw      The bytes.
			 *
			 * @return object
			 */
			public function parse(string $filename, string $raw): object {
				return $this->parsed;
			}//end parse()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($parser);

		return new SavedMailImport(
			cases: $this->cases,
			appManager: $apps,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end importAnswering()

	/**
	 * Recipients given as a single string are not read, and a subject that is not a plain value
	 * titles the message after its file; a numeric sender is read as text.
	 *
	 * @return void
	 */
	public function testPartsThatAreNotPlainValuesAreLeftEmpty(): void {
		$parsed = new class {
			/**
			 * A subject that is a list.
			 *
			 * @return array<int, string>
			 */
			public function getSubject(): array {
				return ['not', 'a', 'subject'];
			}//end getSubject()

			/**
			 * A numeric sender.
			 *
			 * @return integer
			 */
			public function getFrom(): int {
				return 42;
			}//end getFrom()

			/**
			 * Recipients as one string.
			 *
			 * @return string
			 */
			public function getTo(): string {
				return 'zaken@gemeente.nl';
			}//end getTo()
		};
		$this->cases->expects($this->once())
			->method('recordReceivedEmail')
			->with('case-1', '42', '', 'bezwaar.msg', '', '')
			->willReturn('msg-1');

		$outcome = $this->importAnswering(parsed: $parsed)->import(caseId: 'case-1', fileName: 'bezwaar.msg', raw: 'CFB-bytes');

		self::assertSame(
			['outcome' => SavedMailImport::OUTCOME_IMPORTED, 'reason' => '', 'subject' => 'bezwaar.msg', 'from' => '42', 'receivedAt' => ''],
			$outcome
		);
	}//end testPartsThatAreNotPlainValuesAreLeftEmpty()

	/**
	 * Only blank recipients give no recipient; the first non-blank one is used otherwise.
	 *
	 * @return void
	 */
	public function testBlankRecipientsAreSkipped(): void {
		$blank = new class {
			/**
			 * Only blank recipients.
			 *
			 * @return array<int, string>
			 */
			public function getTo(): array {
				return ['  ', ''];
			}//end getTo()
		};
		$second = new class {
			/**
			 * A blank recipient before a real one.
			 *
			 * @return array<int, string>
			 */
			public function getTo(): array {
				return [' ', ' info@gemeente.nl '];
			}//end getTo()
		};
		$recipients = [];
		$this->cases->expects($this->exactly(2))
			->method('recordReceivedEmail')
			->willReturnCallback(
				static function (string $caseId, string $from, string $recipient) use (&$recipients): string {
					$recipients[] = $recipient;
					return 'msg';
				}
			);

		$this->importAnswering(parsed: $blank)->import(caseId: 'case-1', fileName: 'a.eml', raw: 'x');
		$this->importAnswering(parsed: $second)->import(caseId: 'case-1', fileName: 'b.eml', raw: 'x');

		self::assertSame(['', 'info@gemeente.nl'], $recipients);
	}//end testBlankRecipientsAreSkipped()
}//end class
