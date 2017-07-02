<?php
namespace Clockwork\DataSource;

use Clockwork\DataSource\DataSource;
use Clockwork\Request\Request;
use Psr\Http\Message\ServerRequestInterface as PsrRequest;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * Data source providing nearly the same amount of data obtainable from PHP Superglobals, but from PSR-7 Request and response interfaces
 */
class PsrMessageDataSource extends DataSource
{
	/**
	 * PSR Messages
	 */
	protected $psrRequest;
	protected $psrResponse;
	
	public function __construct(PsrRequest $psrRequest = null, PsrResponse $psrResponse = null) {
		$this->psrRequest = $psrRequest;
		$this->psrResponse = $psrResponse;
	}
	
	/**
	 * Add request time, method, URI, headers, get and post data, session data, response status and time to the request
	 */
	public function resolve(Request $request)
	{
		if($this->psrRequest !== null) {
			$request->method         = $this->psrRequest->getMethod();
			$request->uri            = $this->psrRequest->getUri()->__toString();
			$request->headers        = $this->getRequestHeaders();
			$request->getData        = $this->sanitize($this->psrRequest->getQueryParams());
			$request->postData       = $this->sanitize($this->psrRequest->getParsedBody());
			$request->cookies        = $this->sanitize($this->psrRequest->getCookieParams());
			$request->time           = $this->getRequestTime();
			
		}
		
		if($this->psrResponse !== null) {
			$request->responseStatus = $this->psrResponse->getStatusCode();
			$request->responseTime = $this->getResponseTime();
		}

		return $request;
	}
	
	/**
	 * Replace unserializable items in array, attempt to remove passwords
	 */
	protected function sanitize($array)
	{
		if($array === null) {
			return null;
		}
		
		return $this->removePasswords(
			$this->replaceUnserializable($array)
		);
	}

	/**
	 * Return response time in most precise form, fetching it from ServerParams
	 */
	protected function getRequestTime()
	{
		$env = $this->psrRequest->getServerParams();
		
		if (isset($env['REQUEST_TIME_FLOAT'])) {
			return $env['REQUEST_TIME_FLOAT'];
		} elseif (isset($env['REQUEST_TIME'])) {
			return $env['REQUEST_TIME'];
		}
		
		return null;
	}
	
	/**
	 * Return response time (current time, assuming most application scripts have already run at this point)
	 */
	protected function getResponseTime()
	{
		return microtime(true);
	}
	
	/**
	 * Return headers
	 */
	protected function getRequestHeaders()
	{
		$headers = array();
		
		foreach ($this->psrRequest->getHeaders() as $header => $values) {
			if (strtoupper(substr($header, 0, 5)) === 'HTTP_') {
				$header = substr($header, 5);
			}
			
			$header = str_replace('_', ' ', $header);
			$header = ucwords(strtolower($header));
			$header = str_replace(' ', '-', $header);
			
			$headers[$header] = $values;
		}
		
		ksort($headers);
		
		return $headers;
	}
}