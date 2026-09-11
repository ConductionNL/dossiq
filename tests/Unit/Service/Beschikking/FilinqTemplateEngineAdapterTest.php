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
	 * The FQCN filinq's template service answers to on a renamed instance.
	 *
	 * @var string
	 */
	private const FILINQ_TEMPLATES = 'OCA\Filinq\Service\TemplateService';

	/**
	 * The FQCN filinq's template version chain answers to.
	 *
	 * @var string
	 */
	private const FILINQ_TEMPLATE_VERSIONS = 'OCA\Filinq\Service\TemplateVersionService';

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

		$this->bindFilinq(documents: $service, templates: null);

		return $service;
	}//end givenFilinq()

	/**
	 * Bind the container to whichever filinq services a test needs.
	 *
	 * @param object|null $documents Filinq's DocumentService double, or null when absent.
	 * @param object|null $templates Filinq's TemplateService double, or null when absent.
	 * @param object|null $versions Filinq's TemplateVersionService double, or null when absent.
	 *
	 * @return void
	 */
	private function bindFilinq(?object $documents, ?object $templates, ?object $versions = null): void {
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($documents, $templates, $versions): object {
				if ($id === self::FILINQ_DOCUMENTS && $documents !== null) {
					return $documents;
				}

				if ($id === self::FILINQ_TEMPLATES && $templates !== null) {
					return $templates;
				}

				if ($id === self::FILINQ_TEMPLATE_VERSIONS && $versions !== null) {
					return $versions;
				}

				throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}
		);
	}//end bindFilinq()

	/**
	 * A filinq template lookup that answers with a canned object.
	 *
	 * @param array<string, mixed> $template What filinq holds for the template.
	 *
	 * @return object The double; its `$asked` holds every id looked up.
	 */
	private function templateDouble(array $template): object {
		return new class($template) {

			/**
			 * Every template id asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $template The canned template.
			 */
			public function __construct(private array $template) {
			}

			/**
			 * Filinq's template lookup.
			 *
			 * @param string $id The template id.
			 *
			 * @return array<string, mixed> The template.
			 */
			public function getTemplate(string $id): array {
				$this->asked[] = $id;
				return (['id' => $id] + $this->template);
			}
		};
	}//end templateDouble()

	/**
	 * A filinq version chain that answers with canned rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The chain, newest first.
	 *
	 * @return object The double; its `$asked` holds every call's arguments.
	 */
	private function versionChainDouble(array $rows): object {
		return new class($rows) {

			/**
			 * Every call, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows The canned chain.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Filinq's version listing.
			 *
			 * @param string $templateId The template id.
			 * @param int $limit How many entries to return.
			 * @param int $offset Where to start.
			 *
			 * @return array<string, mixed> The paginated chain.
			 */
			public function getVersions(string $templateId, int $limit = 20, int $offset = 0): array {
				$this->asked[] = ['templateId' => $templateId, 'limit' => $limit, 'offset' => $offset];
				return ['results' => $this->rows, 'total' => count($this->rows)];
			}
		};
	}//end versionChainDouble()

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
	/**
	 * A render with no acting user is refused rather than answered.
	 *
	 * Filinq will not store a generated document without an owning user, so
	 * this render would hand back a composition with a null fileId. Refusing is
	 * the point: a composition naming no file is the mock's failure again.
	 *
	 * @return void
	 */
	public function testRenderRefusesWithoutAnActingUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->givenFilinq(['content' => self::PDF_BYTES, 'format' => 'pdf', 'output' => ['fileId' => 1]]);

		$adapter = new FilinqTemplateEngineAdapter(
			$this->container,
			$this->settings,
			$session,
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/user_required/');

		$adapter->render('tpl-abc', ['caseId' => 'case-1']);
	}//end testRenderRefusesWithoutAnActingUser()

	/**
	 * A render with no case id sends no data reference and a plain filename.
	 *
	 * @return void
	 */
	public function testRenderWithoutACaseSendsNoDataRefs(): void {
		$this->settings->method('getConfigValue')->willReturn('zaken');
		$filinq = $this->givenFilinq(
			['content' => 'not a pdf', 'format' => 'html', 'output' => ['fileId' => 12]]
		);

		$composition = $this->adapter()->render('tpl-abc', ['overrides' => []]);

		$this->assertSame([], $filinq->calls[0]['dataRefs']);
		$this->assertSame('beschikking', $filinq->calls[0]['options']['filename']);
		$this->assertSame(0, $composition['paginas'], 'a non-PDF carries no page structure to read');
		$this->assertSame('html', $composition['format']);
	}//end testRenderWithoutACaseSendsNoDataRefs()

	/**
	 * The version reported is the one filinq holds, not a constant.
	 *
	 * The mock answered `v1` for every template on every date, which is not a
	 * version at all. This reads filinq's own value.
	 *
	 * @return void
	 */
	public function testResolveVersionReadsFilinqsTemplateVersion(): void {
		$templates = new class {

			/**
			 * Every template id asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Filinq's template lookup.
			 *
			 * @param string $id The template id.
			 *
			 * @return array<string, mixed> The template.
			 */
			public function getTemplate(string $id): array {
				$this->asked[] = $id;
				return ['id' => $id, 'name' => 'Beschikking', 'version' => 7];
			}
		};
		$this->bindFilinq(documents: null, templates: $templates);

		$version = $this->adapter()->resolveVersion('tpl-abc', '2026-09-09');

		$this->assertSame(['tpl-abc'], $templates->asked, 'filinq must be asked, not assumed');
		$this->assertSame(
			['templateId' => 'tpl-abc', 'version' => 'v7', 'effectiveDate' => '2026-09-09'],
			$version
		);
	}//end testResolveVersionReadsFilinqsTemplateVersion()

	/**
	 * The version in force is the chain entry the effective date selects.
	 *
	 * The date used to be echoed back untouched, so a beschikking issued in
	 * March reported whatever the template said today. Here the chain holds a
	 * version created after the effective date and two before it; the answer
	 * must be the newest of the two, not the newest of the three.
	 *
	 * @return void
	 */
	public function testResolveVersionPicksTheChainEntryInForceOnTheDate(): void {
		$templates = $this->templateDouble(['name' => 'Beschikking', '@self' => ['version' => '0.0.9']]);
		$versions = $this->versionChainDouble(
			[
				['version' => 3, '@self' => ['created' => '2026-08-01T09:00:00+00:00']],
				['version' => 2, '@self' => ['created' => '2026-03-04T09:00:00+00:00']],
				['version' => 1, '@self' => ['created' => '2026-01-05T09:00:00+00:00']],
			]
		);
		$this->bindFilinq(documents: null, templates: $templates, versions: $versions);

		$version = $this->adapter()->resolveVersion('tpl-abc', '2026-03-15');

		$this->assertSame(
			[['templateId' => 'tpl-abc', 'limit' => 100, 'offset' => 0]],
			$versions->asked,
			'the chain must be queried for this template'
		);
		$this->assertSame(
			['templateId' => 'tpl-abc', 'version' => 'v2', 'effectiveDate' => '2026-03-15'],
			$version
		);
	}//end testResolveVersionPicksTheChainEntryInForceOnTheDate()

	/**
	 * A version created on the effective date itself is in force on it.
	 *
	 * The boundary matters: a template revised in the morning and used to
	 * decide in the afternoon must report the revision, not its predecessor.
	 *
	 * @return void
	 */
	public function testAVersionCreatedOnTheEffectiveDateIsInForce(): void {
		$templates = $this->templateDouble(['@self' => ['version' => '0.0.9']]);
		$versions = $this->versionChainDouble(
			[
				['version' => 4, '@self' => ['created' => '2026-03-15T08:30:00+00:00']],
				['version' => 3, '@self' => ['created' => '2026-03-14T08:30:00+00:00']],
			]
		);
		$this->bindFilinq(documents: null, templates: $templates, versions: $versions);

		$version = $this->adapter()->resolveVersion('tpl-abc', '2026-03-15');

		$this->assertSame('v4', $version['version']);
	}//end testAVersionCreatedOnTheEffectiveDateIsInForce()

	/**
	 * Without a chain, the template's own OpenRegister version is read.
	 *
	 * 🔴 THE BUG THIS PINS. The adapter read `$template['version']`, which
	 * filinq never sets: `getTemplate()` returns an OpenRegister serialisation
	 * that keeps the version under `@self`, and filinq's `template` schema
	 * declares no `version` property. The coalesce to 1 therefore always
	 * fired, and every beschikking in every install recorded version 1. A
	 * template that has never been revised has no chain either, so this is the
	 * common case, not the edge.
	 *
	 * @return void
	 */
	public function testResolveVersionReadsTheVersionOpenRegisterKeepsUnderSelf(): void {
		$templates = $this->templateDouble(['name' => 'Beschikking', '@self' => ['version' => '0.0.4']]);
		$this->bindFilinq(documents: null, templates: $templates, versions: $this->versionChainDouble([]));

		$version = $this->adapter()->resolveVersion('tpl-abc', '2026-09-09');

		$this->assertSame('0.0.4', $version['version'], 'the version filinq holds, not a default');
	}//end testResolveVersionReadsTheVersionOpenRegisterKeepsUnderSelf()

	/**
	 * A template filinq holds no version for at all is refused.
	 *
	 * `v1` is not a safe default on a document a citizen can appeal.
	 *
	 * @return void
	 */
	public function testResolveVersionRefusesATemplateWithNoVersionAnywhere(): void {
		$templates = $this->templateDouble(['name' => 'Beschikking']);
		$this->bindFilinq(documents: null, templates: $templates, versions: $this->versionChainDouble([]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_template_unversioned/');

		$this->adapter()->resolveVersion('tpl-abc', '2026-09-09');
	}//end testResolveVersionRefusesATemplateWithNoVersionAnywhere()

	/**
	 * A template filinq does not know is refused, not defaulted.
	 *
	 * @return void
	 */
	public function testResolveVersionRefusesAnUnknownTemplate(): void {
		$templates = new class {

			/**
			 * Filinq's template lookup, for a template that is not there.
			 *
			 * @param string $id The template id.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function getTemplate(string $id): array {
				throw new \RuntimeException('not found');
			}
		};
		$this->bindFilinq(documents: null, templates: $templates);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_template_unknown/');

		$this->adapter()->resolveVersion('tpl-missing', '2026-09-09');
	}//end testResolveVersionRefusesAnUnknownTemplate()

	/**
	 * Resolving a version without filinq is refused rather than guessed.
	 *
	 * @return void
	 */
	public function testResolveVersionRefusesWhenFilinqIsAbsent(): void {
		$this->bindFilinq(documents: null, templates: null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_unavailable/');

		$this->adapter()->resolveVersion('tpl-abc', '2026-09-09');
	}//end testResolveVersionRefusesWhenFilinqIsAbsent()

	/**
	 * A filinq render that throws is reported as a failure, not a composition.
	 *
	 * @return void
	 */
	public function testRenderRefusesWhenFilinqThrows(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$service = new class {

			/**
			 * Filinq's document generation, which fails here.
			 *
			 * @param string $templateId The template id.
			 * @param array<int, mixed> $dataRefs The OpenRegister references.
			 * @param array<string, mixed> $options The generation options.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				throw new \RuntimeException('template engine exploded');
			}
		};
		$this->bindFilinq(documents: $service, templates: null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_render_failed: template engine exploded/');

		$this->adapter()->render('tpl-abc', ['caseId' => 'case-1']);
	}//end testRenderRefusesWhenFilinqThrows()

}//end class
