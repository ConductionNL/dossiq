<?php

/**
 * The beschikking template seam reaches filinq, or says it did not.
 *
 * WHAT THESE TESTS GUARD, and why the shape of the assertions matters more
 * than their number. The seam's only implementation was
 * `MockTemplateEngineAdapter`, which hashed its own arguments into a fileId
 * and a checksum and reported a constant four pages. Every assertion anyone
 * could write about its RETURN held: the format was a format, the checksum was
 * a checksum, the shape was right. Nothing had been rendered. So the
 * assertions below are about the CALL filinq receives and about metadata that
 * can only come from bytes filinq produced. Point this adapter at nothing and
 * they fail; leave it pointed at a mock and they fail.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the filinq-backed template adapter.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class FilinqTemplateEngineAdapterTest extends TestCase {

	/**
	 * The FQCN filinq's document service answers to on a renamed instance.
	 *
	 * @var string
	 */
	private const FILINQ_DOCUMENTS = 'OCA\Filinq\Service\DocumentService';

	/**
	 * A two-page PDF, as far as the page counter is concerned.
	 *
	 * @var string
	 */
	private const PDF_BYTES = "%PDF-1.7\n/Type /Page x\n/Type /Page y\n%%EOF";

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settings;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Set up the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the adapter under test.
	 *
	 * @return FilinqTemplateEngineAdapter The adapter.
	 */
	private function adapter(): FilinqTemplateEngineAdapter {
		return new FilinqTemplateEngineAdapter(
			$this->container,
			$this->settings,
			$this->userSession,
			$this->createMock(LoggerInterface::class)
		);
	}//end adapter()

	/**
	 * Point the container at a filinq document service that records its calls.
	 *
	 * @param array<string, mixed> $result What filinq answers with.
	 *
	 * @return object The recording double; its `$calls` holds every invocation.
	 */
	private function givenFilinq(array $result): object {
		$service = new class($result) {

			/**
			 * Every invocation, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $result The canned answer.
			 */
			public function __construct(private array $result) {
			}

			/**
			 * Filinq's document-generation entry point.
			 *
			 * @param string $templateId The template id.
			 * @param array<int, mixed> $dataRefs The OpenRegister references.
			 * @param array<string, mixed> $options The generation options.
			 *
			 * @return array<string, mixed> The generation result.
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->calls[] = ['templateId' => $templateId, 'dataRefs' => $dataRefs, 'options' => $options];
				return $this->result;
			}
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === self::FILINQ_DOCUMENTS) {
					return $service;
				}

				throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}
		);

		return $service;
	}//end givenFilinq()

	/**
	 * The template id, the case reference and the context all reach filinq.
	 *
	 * @return void
	 */
	public function testRenderHandsTheTemplateAndCaseToFilinq(): void {
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'zaken'],
				['case_schema', '', 'zaak'],
			]
		);
		$filinq = $this->givenFilinq(
			['content' => self::PDF_BYTES, 'format' => 'pdf', 'output' => ['fileId' => 7788]]
		);

		$this->adapter()->render('tpl-abc', ['caseId' => 'case-1', 'overrides' => ['decisionType' => 'toekenning']]);

		$calls = $filinq->calls;

		$this->assertCount(1, $calls, 'filinq must be called exactly once per render');
		$this->assertSame('tpl-abc', $calls[0]['templateId']);
		$this->assertSame(
			[['register' => 'zaken', 'schema' => 'zaak', 'id' => 'case-1']],
			$calls[0]['dataRefs'],
			'the case object must be handed to filinq, not just the ad-hoc context'
		);
		$this->assertSame('alice', $calls[0]['options']['userId']);
		$this->assertSame('both', $calls[0]['options']['output']['mode']);
		$this->assertSame('case-1', $calls[0]['options']['adHocData']['caseId']);
	}//end testRenderHandsTheTemplateAndCaseToFilinq()

	/**
	 * The composition describes filinq's bytes, not the render request.
	 *
	 * This is the assertion the mock could never satisfy: it hashed
	 * `[templateId, context]`, so its checksum was a function of the REQUEST.
	 * Here the request is held fixed and only the produced document changes,
	 * and the checksum has to move with it.
	 *
	 * @return void
	 */
	public function testCompositionDescribesTheProducedDocument(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$this->givenFilinq(['content' => self::PDF_BYTES, 'format' => 'pdf', 'output' => ['fileId' => 4242]]);

		$composition = $this->adapter()->render('tpl-abc', ['caseId' => 'case-1']);

		$this->assertSame('4242', $composition['fileId'], 'the fileId must be the one filinq stored');
		$this->assertSame(hash('sha256', self::PDF_BYTES), $composition['checksumSha256']);
		$this->assertSame(2, $composition['paginas'], 'the page count is read out of the PDF, not assumed');
		$this->assertSame('pdf', $composition['format']);
	}//end testCompositionDescribesTheProducedDocument()

	/**
	 * A render that produced no stored file is refused, not reported.
	 *
	 * @return void
	 */
	public function testRenderRefusesWhenFilinqStoredNothing(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$this->givenFilinq(['content' => self::PDF_BYTES, 'format' => 'pdf', 'output' => ['fileId' => null]]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_render_empty/');

		$this->adapter()->render('tpl-abc', ['caseId' => 'case-1']);
	}//end testRenderRefusesWhenFilinqStoredNothing()

	/**
	 * An absent filinq throws rather than answering with a composition.
	 *
	 * @return void
	 */
	public function testRenderRefusesWhenFilinqIsAbsent(): void {
		$this->container->method('get')->willThrowException(
			new class('absent') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
			}
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_unavailable/');

		$this->adapter()->render('tpl-abc', ['caseId' => 'case-1']);
	}//end testRenderRefusesWhenFilinqIsAbsent()
}//end class
