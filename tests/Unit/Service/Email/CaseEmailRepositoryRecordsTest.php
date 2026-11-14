<?php
/**
 * Unit tests for CaseEmailRepository: the case record and its template
 * variables, the email messages it records on a case, and the case lookup by
 * identifier.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

require_once __DIR__ . '/RecordingObjectService.php';

use JsonSerializable;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Case records, template variables, message records and identifier lookup.
 *
 * @covers \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @uses   \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class CaseEmailRepositoryRecordsTest extends TestCase {

	/**
	 * A repository over $objects and the given app config.
	 *
	 * @param RecordingObjectService|null $objects The object service, null when OpenRegister is absent.
	 * @param array<string, string>       $config  App config values.
	 *
	 * @return CaseEmailRepository
	 */
	private function repository(?RecordingObjectService $objects, array $config = []): CaseEmailRepository {
		$config += ['register' => 'dossiq', 'case_schema' => 'case', 'email_message_schema' => 'emailMessage', 'email_template_schema' => 'emailTemplate'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new CaseEmailRepository(settingsService: $settings);
	}//end repository()

	/**
	 * The case record is read from the case schema, serialised when it is an
	 * entity, and empty when it is missing or not readable.
	 *
	 * @return void
	 */
	public function testTheCaseRecord(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised case.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['identifier' => 'Z-1'];
			}
		};
		$objects = new RecordingObjectService(found: $entity);
		$this->assertSame(expected: ['identifier' => 'Z-1'], actual: $this->repository(objects: $objects)->loadCaseRecord(caseId: 'c1'));
		$this->assertSame(expected: [['find', ['id' => 'c1', 'register' => 'dossiq', 'schema' => 'case']]], actual: $objects->calls);

		$this->assertSame(expected: [], actual: $this->repository(objects: new RecordingObjectService(found: null))->loadCaseRecord(caseId: 'c1'));
		$this->assertSame(expected: [], actual: $this->repository(objects: new RecordingObjectService(found: 'x'))->loadCaseRecord(caseId: 'c1'));
		$this->assertSame(expected: [], actual: $this->repository(objects: null)->loadCaseRecord(caseId: 'c1'));
	}//end testTheCaseRecord()

	/**
	 * The template variables use the editor's names, fall back to the nested
	 * contact, and keep the old spellings as aliases.
	 *
	 * @return void
	 */
	public function testTemplateVariablesFromACase(): void {
		$repository = $this->repository(objects: null);

		$variables = $repository->flattenCaseVariables(
			caseObj: [
				'identifier' => 'Z-7',
				'title' => 'Bouwvergunning',
				'startDate' => '2026-10-01',
				'assignee' => 'alice',
				'contact' => ['name' => 'Jan', 'email' => 'jan@example.test', 'phone' => '0600'],
				'caseTypeTitle' => 'Vergunning',
			]
		);

		$this->assertSame(
			expected: [
				'zaakNummer' => 'Z-7',
				'titel' => 'Bouwvergunning',
				'startDate' => '2026-10-01',
				'endDate' => '',
				'deadline' => '',
				'status' => '',
				'handler' => 'alice',
				'contactNaam' => 'Jan',
				'contactEmail' => 'jan@example.test',
				'contactTelefoon' => '0600',
				'zaaktypeNaam' => 'Vergunning',
				'zaaktypeOmschrijving' => '',
				'title' => 'Bouwvergunning',
				'startdatum' => '2026-10-01',
			],
			actual: $variables
		);

		$flat = $repository->flattenCaseVariables(caseObj: ['contactName' => 'Piet', 'contact' => ['name' => 'ignored']]);
		$this->assertSame(expected: 'Piet', actual: $flat['contactNaam']);
		$this->assertSame(expected: [], actual: $repository->flattenCaseVariables(caseObj: []));
	}//end testTemplateVariablesFromACase()

	/**
	 * Case variables are loaded from the case record.
	 *
	 * @return void
	 */
	public function testCaseVariablesAreLoadedFromTheRecord(): void {
		$variables = $this->repository(objects: new RecordingObjectService(found: ['identifier' => 'Z-9']))->loadCaseVariables(caseId: 'c9');
		$this->assertSame(expected: 'Z-9', actual: $variables['zaakNummer']);
		$this->assertSame(expected: [], actual: $this->repository(objects: null)->loadCaseVariables(caseId: 'c9'));
	}//end testCaseVariablesAreLoadedFromTheRecord()

	/**
	 * A received email is recorded on the case as an inbound message with
	 * its reply reference and a generated message id.
	 *
	 * @return void
	 */
	public function testAReceivedEmailIsRecordedInbound(): void {
		$objects = new RecordingObjectService();

		$messageId = $this->repository(objects: $objects)->recordReceivedEmail(
			caseId: 'c1',
			from: 'jan@example.test',
			recipient: 'zaken@gemeente.test',
			subject: 'Re: Z-1',
			body: 'Dank',
			inReplyTo: 'msg-abc'
		);

		$this->assertMatchesRegularExpression(pattern: '/^msg-[0-9a-f]{13}$/', string: $messageId);
		$this->assertCount(expectedCount: 1, haystack: $objects->calls);
		[$method, $arguments] = $objects->calls[0];
		$this->assertSame(expected: 'save', actual: $method);
		$this->assertSame(expected: ['dossiq', 'emailMessage'], actual: [$arguments['register'], $arguments['schema']]);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', string: $arguments['object']['receivedAt']);
		unset($arguments['object']['receivedAt']);
		$this->assertSame(
			expected: [
				'case' => 'c1',
				'direction' => 'inbound',
				'from' => 'jan@example.test',
				'to' => 'zaken@gemeente.test',
				'subject' => 'Re: Z-1',
				'body' => 'Dank',
				'messageId' => $messageId,
				'inReplyTo' => 'msg-abc',
			],
			actual: $arguments['object']
		);
	}//end testAReceivedEmailIsRecordedInbound()

	/**
	 * Without OpenRegister or a message schema nothing is stored, but a
	 * message id is still handed back.
	 *
	 * @return void
	 */
	public function testNothingIsStoredWithoutStoreOrSchema(): void {
		$objects = new RecordingObjectService();
		$repository = $this->repository(objects: $objects, config: ['email_message_schema' => '']);

		$this->assertStringStartsWith(
			prefix: 'msg-',
			string: $repository->recordReceivedEmail(caseId: 'c', from: 'f', recipient: 't', subject: 's', body: 'b', inReplyTo: '')
		);
		$this->assertStringStartsWith(
			prefix: 'msg-',
			string: $repository->recordSentEmail(caseId: 'c', fromAddress: 'f', to: 't', subject: 's', body: 'b')
		);
		$this->assertStringStartsWith(
			prefix: 'msg-',
			string: $this->repository(objects: null)->recordReceivedEmail(caseId: 'c', from: 'f', recipient: 't', subject: 's', body: 'b', inReplyTo: '')
		);
		$this->assertSame(expected: [], actual: $objects->calls);
	}//end testNothingIsStoredWithoutStoreOrSchema()

	/**
	 * A case is found by its identifier: its id, else its uuid, else nothing.
	 *
	 * @return void
	 */
	public function testACaseIsFoundByIdentifier(): void {
		$objects = new RecordingObjectService(searched: [['id' => 'c1', 'uuid' => 'u1']]);
		$this->assertSame(expected: 'c1', actual: $this->repository(objects: $objects)->findCaseIdByIdentifier(identifier: 'Z-1'));
		$this->assertSame(
			expected: [['search', ['register' => 'dossiq', 'schema' => 'case', 'filters' => ['identifier' => 'Z-1', '_limit' => 1]]]],
			actual: $objects->calls
		);

		$this->assertSame(
			expected: 'u2',
			actual: $this->repository(objects: new RecordingObjectService(searched: [['uuid' => 'u2']]))->findCaseIdByIdentifier(identifier: 'Z-2')
		);
		$this->assertNull(
			actual: $this->repository(objects: new RecordingObjectService(searched: [['title' => 'x']]))->findCaseIdByIdentifier(identifier: 'Z-3')
		);
		$this->assertNull(actual: $this->repository(objects: new RecordingObjectService(searched: []))->findCaseIdByIdentifier(identifier: 'Z-4'));
		$this->assertNull(actual: $this->repository(objects: null)->findCaseIdByIdentifier(identifier: 'Z-5'));
	}//end testACaseIsFoundByIdentifier()

	/**
	 * Templates of a case type come from the template schema, at most 100.
	 *
	 * @return void
	 */
	public function testTemplatesOfACaseType(): void {
		$objects = new RecordingObjectService(searched: [['id' => 't1']]);
		$this->assertSame(expected: [['id' => 't1']], actual: $this->repository(objects: $objects)->findTemplatesForCaseType(caseTypeId: 'ct-1'));
		$this->assertSame(
			expected: [['search', ['register' => 'dossiq', 'schema' => 'emailTemplate', 'filters' => ['caseType' => 'ct-1', '_limit' => 100]]]],
			actual: $objects->calls
		);
	}//end testTemplatesOfACaseType()
}//end class
