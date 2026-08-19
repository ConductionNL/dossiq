<?php

/**
 * ContractRequestStub fixture
 *
 * A concrete IRequest implementation shared by the gate-25 contract tests.
 *
 * WHY A CONCRETE STUB AND NOT createMock(IRequest::class)
 * ------------------------------------------------------
 * A PHPUnit mock of IRequest cannot answer `$this->request->getContent()`
 * (getContent() is not on the interface — it is a magic accessor on the real
 * OC\AppFramework\Http\Request), and it cannot answer the `$request->server`
 * / `$request->urlParams` magic properties either. Controllers under test read
 * both, so a mock silently returns null where production returns a body, and
 * the test then measures the mock rather than the controller.
 *
 * It also matters for HONESTY of the assertion: a mock configured with
 * `->method('getParam')->willReturn(...)` returns the SAME value for every
 * key, so a controller that validates three separate required fields cannot be
 * shown to reject any one of them individually. This stub keys off the real
 * parameter name, so "omit exactly one required field" is expressible — which
 * is what makes a 400-on-missing-field assertion able to fail.
 *
 * @category Tests
 * @package  OCA\Procest\Tests
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Fixtures;

use OCP\IRequest;

if (class_exists(ContractRequestStub::class, false) === true) {
	return;
}

/**
 * Concrete IRequest for controller contract tests.
 *
 * Construct with the parameters the endpoint is supposed to receive; omit the
 * ones whose absence you want the controller to reject.
 *
 * @property-read array<string,mixed> $server
 * @property-read array<string,mixed> $urlParams
 */
class ContractRequestStub implements IRequest {

	/**
	 * Request parameters, keyed by name.
	 *
	 * @var array<string,mixed>
	 */
	private array $params;

	/**
	 * Raw request body returned by getContent().
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * HTTP method reported by getMethod().
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Request headers, keyed by lower-cased name.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Magic read-only properties the AppFramework exposes on the real request.
	 *
	 * @var array<string,mixed>
	 */
	private array $magic;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>  $params  Request parameters, keyed by name.
	 * @param string               $content Raw request body for getContent().
	 * @param string               $method  HTTP verb reported by getMethod().
	 * @param array<string,string> $headers Request headers, keyed by name.
	 *
	 * @return void
	 */
	public function __construct(
		array $params = [],
		string $content = '',
		string $method = 'POST',
		array $headers = [],
	) {
		$this->params = $params;
		$this->content = $content;
		$this->method = $method;
		$this->headers = [];
		foreach ($headers as $name => $value) {
			$this->headers[strtolower($name)] = $value;
		}

		$this->magic = [
			'server' => ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $method],
			'urlParams' => [],
			'method' => $method,
			'parameters' => $params,
		];
	}//end __construct()

	/**
	 * Read one of the AppFramework's magic request properties.
	 *
	 * @param string $name Property name.
	 *
	 * @return mixed The property value, or null when unknown.
	 */
	public function __get(string $name): mixed {
		return ($this->magic[$name] ?? null);
	}//end __get()

	/**
	 * Report whether a magic request property is set.
	 *
	 * @param string $name Property name.
	 *
	 * @return bool True when the property exists.
	 */
	public function __isset(string $name): bool {
		return isset($this->magic[$name]);
	}//end __isset()

	/**
	 * Return a request header value by name.
	 *
	 * @param string $name Header name.
	 *
	 * @return string The header value, or '' when absent.
	 */
	public function getHeader(string $name): string {
		return ($this->headers[strtolower($name)] ?? '');
	}//end getHeader()

	/**
	 * Return a query/body parameter.
	 *
	 * @param string $key     Parameter name.
	 * @param mixed  $default Value returned when the parameter was not supplied.
	 *
	 * @return mixed The parameter value, or $default.
	 */
	public function getParam(string $key, mixed $default = null): mixed {
		if (array_key_exists($key, $this->params) === true) {
			return $this->params[$key];
		}

		return $default;
	}//end getParam()

	/**
	 * Return every supplied request parameter.
	 *
	 * @return array<string,mixed>
	 */
	public function getParams(): array {
		return $this->params;
	}//end getParams()

	/**
	 * Return the HTTP method.
	 *
	 * @return string
	 */
	public function getMethod(): string {
		return $this->method;
	}//end getMethod()

	/**
	 * Return an uploaded file by field name.
	 *
	 * @param string $key File field name.
	 *
	 * @return mixed The uploaded-file array, or null.
	 */
	public function getUploadedFile(string $key): mixed {
		return ($this->params['__files'][$key] ?? null);
	}//end getUploadedFile()

	/**
	 * Return a server environment variable.
	 *
	 * @param string $key Variable name.
	 *
	 * @return mixed Always null in tests.
	 */
	public function getEnv(string $key): mixed {
		return null;
	}//end getEnv()

	/**
	 * Return a cookie value by name.
	 *
	 * @param string $key Cookie name.
	 *
	 * @return mixed Always null in tests.
	 */
	public function getCookie(string $key): mixed {
		return null;
	}//end getCookie()

	/**
	 * Return whether this request passes the CSRF check.
	 *
	 * @return bool
	 */
	public function passesCSRFCheck(): bool {
		return true;
	}//end passesCSRFCheck()

	/**
	 * Return whether this request passes a strict cookie check.
	 *
	 * @return bool
	 */
	public function passesStrictCookieCheck(): bool {
		return true;
	}//end passesStrictCookieCheck()

	/**
	 * Return whether this request passes a lax cookie check.
	 *
	 * @return bool
	 */
	public function passesLaxCookieCheck(): bool {
		return true;
	}//end passesLaxCookieCheck()

	/**
	 * Return the unique request id.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'contract-test-request';
	}//end getId()

	/**
	 * Return the remote IP address.
	 *
	 * @return string
	 */
	public function getRemoteAddress(): string {
		return '127.0.0.1';
	}//end getRemoteAddress()

	/**
	 * Return the server protocol.
	 *
	 * @return string
	 */
	public function getServerProtocol(): string {
		return 'HTTP/1.1';
	}//end getServerProtocol()

	/**
	 * Return the HTTP scheme.
	 *
	 * @return string
	 */
	public function getHttpProtocol(): string {
		return 'http';
	}//end getHttpProtocol()

	/**
	 * Return the full request URI.
	 *
	 * @return string
	 */
	public function getRequestUri(): string {
		return '/apps/procest/';
	}//end getRequestUri()

	/**
	 * Return the raw path info segment.
	 *
	 * @return string
	 */
	public function getRawPathInfo(): string {
		return '';
	}//end getRawPathInfo()

	/**
	 * Return the decoded path info segment.
	 *
	 * @return mixed
	 */
	public function getPathInfo(): mixed {
		return '';
	}//end getPathInfo()

	/**
	 * Return the script name.
	 *
	 * @return string
	 */
	public function getScriptName(): string {
		return '';
	}//end getScriptName()

	/**
	 * Return whether the request came from one of the given user agents.
	 *
	 * @param array<int,string> $agent Agent patterns to match.
	 *
	 * @return bool
	 */
	public function isUserAgent(array $agent): bool {
		return false;
	}//end isUserAgent()

	/**
	 * Return the insecure server host.
	 *
	 * @return string
	 */
	public function getInsecureServerHost(): string {
		return 'localhost';
	}//end getInsecureServerHost()

	/**
	 * Return the server host.
	 *
	 * @return string
	 */
	public function getServerHost(): string {
		return 'localhost';
	}//end getServerHost()

	/**
	 * Throw if a JSON decoding error occurred while parsing the body.
	 *
	 * @return void
	 */
	public function throwDecodingExceptionIfAny(): void {
	}//end throwDecodingExceptionIfAny()

	/**
	 * Return the requested response format.
	 *
	 * @return string|null
	 */
	public function getFormat(): ?string {
		return null;
	}//end getFormat()

	/**
	 * Return the raw request body.
	 *
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}//end getContent()
}//end class
