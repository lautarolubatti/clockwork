<?php namespace Clockwork;

use Clockwork\Authentication\AuthenticatorInterface;
use Clockwork\Authentication\NullAuthenticator;
use Clockwork\DataSource\DataSourceInterface;
use Clockwork\Helpers\Serializer;
use Clockwork\Request\Log;
use Clockwork\Request\Request;
use Clockwork\Request\RequestType;
use Clockwork\Request\ShouldCollect;
use Clockwork\Request\ShouldRecord;
use Clockwork\Request\Timeline\Timeline;
use Clockwork\Storage\StorageInterface;

use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

/**
 * Main Clockwork class
 */
class Clockwork implements LoggerInterface
{
	/**
	 * Clockwork version
	 */
	const VERSION = '4.1.7';

	/**
	 * Array of data sources, these objects provide data to be stored in a request object
	 */
	protected $dataSources = [];

	/**
	 * Request object, data structure which stores data about current application request
	 */
	protected $request;

	/**
	 * Storage object, provides implementation for storing and retrieving request objects
	 */
	protected $storage;

	// Authenticator implementation, authenticates requests for clockwork metadata
	protected $authenticator;

	/**
	 * Request\Log instance, data structure which stores data for the log view
	 */
	protected $log;

	// Callback to filter whether the request should be collected
	protected $shouldCollect;

	// Callback to filter whether the request should be recorded
	protected $shouldRecord;

	/**
	 * Create a new Clockwork instance with default request object
	 */
	public function __construct()
	{
		$this->request = new Request;
		$this->log = new Log;
		$this->authenticator = new NullAuthenticator;

		$this->shouldCollect = new ShouldCollect;
		$this->shouldRecord = new ShouldRecord;
	}

	/**
	 * Add a new data source
	 */
	public function addDataSource(DataSourceInterface $dataSource)
	{
		$this->dataSources[] = $dataSource;

		return $this;
	}

	/**
	 * Return array of all added data sources
	 */
	public function getDataSources()
	{
		return $this->dataSources;
	}

	/**
	 * Return the request object
	 */
	public function getRequest()
	{
		return $this->request;
	}

	/**
	 * Set a custom request object
	 */
	public function setRequest(Request $request)
	{
		$this->request = $request;

		return $this;
	}

	/**
	 * Add data from all data sources to request
	 */
	public function resolveRequest()
	{
		foreach ($this->dataSources as $dataSource) {
			$dataSource->resolve($this->request);
		}

		// merge global log with data collected from data sources
		$this->request->log = array_merge($this->request->log, $this->log->toArray());

		// sort log data by time
		uasort($this->request->log, function($a, $b) {
			if ($a['time'] == $b['time']) return 0;
			return $a['time'] < $b['time'] ? -1 : 1;
		});

		$this->request->timeline()->finalize($this->request->time);

		return $this;
	}

	// Resolve the current request as a "command" type request with command-specific data
	public function resolveAsCommand($name, $exitCode = null, $arguments = [], $options = [], $argumentsDefaults = [], $optionsDefaults = [], $output = null)
	{
		$this->resolveRequest();

		$this->request->type = RequestType::COMMAND;
		$this->request->commandName = $name;
		$this->request->commandArguments = $arguments;
		$this->request->commandArgumentsDefaults = $argumentsDefaults;
		$this->request->commandOptions = $options;
		$this->request->commandOptionsDefaults = $optionsDefaults;
		$this->request->commandExitCode = $exitCode;
		$this->request->commandOutput = $output;

		return $this;
	}

	// Resolve the current request as a "queue-job" type request with queue-job-specific data
	public function resolveAsQueueJob($name, $description = null, $status = 'processed', $payload = [], $queue = null, $connection = null, $options = [])
	{
		$this->resolveRequest();

		$this->request->type = RequestType::QUEUE_JOB;
		$this->request->jobName = $name;
		$this->request->jobDescription = $description;
		$this->request->jobStatus = $status;
		$this->request->jobPayload = (new Serializer)->normalize($payload);
		$this->request->jobQueue = $queue;
		$this->request->jobConnection = $connection;
		$this->request->jobOptions = (new Serializer)->normalizeEach($options);

		return $this;
	}

	// Resolve the current request as a "test" type request with test-specific data, accepts test name, status, status
	// message in case of failure and array of ran asserts
	public function resolveAsTest($name, $status = 'passed', $statusMessage = null, $asserts = [])
	{
		$this->resolveRequest();

		$this->request->type = RequestType::TEST;
		$this->request->testName = $name;
		$this->request->testStatus = $status;
		$this->request->testStatusMessage = $statusMessage;

		foreach ($asserts as $assert) {
			$this->request->addTestAssert($assert['name'], $assert['arguments'], $assert['passed'], $assert['trace']);
		}

		return $this;
	}

	// Extends the request with additional data form all data sources when being shown in the Clockwork app
	public function extendRequest(Request $request = null)
	{
		foreach ($this->dataSources as $dataSource) {
			$dataSource->extend($request ?: $this->request);
		}

		return $this;
	}

	/**
	 * Store request via storage object
	 */
	public function storeRequest()
	{
		return $this->storage->store($this->request);
	}

	// Reset the log, timeline and all data sources to an empty state, clearing any collected data
	public function reset()
	{
		foreach ($this->dataSources as $dataSource) {
			$dataSource->reset();
		}

		$this->log = new Log;

		return $this;
	}

	public function shouldCollect($shouldCollect = null)
	{
		if ($shouldCollect instanceof Closure) return $this->shouldCollect->callback($shouldCollect);

		if (is_array($shouldCollect)) return $this->shouldCollect->merge($shouldCollect);

		return $this->shouldCollect;
	}

	public function shouldRecord($shouldRecord = null)
	{
		if ($shouldRecord instanceof Closure) return $this->shouldRecord->callback($shouldRecord);

		if (is_array($shouldRecord)) return $this->shouldRecord->merge($shouldRecord);

		return $this->shouldRecord;
	}

	/**
	 * Return the storage object
	 */
	public function getStorage()
	{
		return $this->storage;
	}

	/**
	 * Set a custom storage object
	 */
	public function setStorage(StorageInterface $storage)
	{
		$this->storage = $storage;

		return $this;
	}

	/**
	 * Return the authenticator object
	 */
	public function getAuthenticator()
	{
		return $this->authenticator;
	}

	/**
	 * Set a custom authenticator object
	 */
	public function setAuthenticator(AuthenticatorInterface $authenticator)
	{
		$this->authenticator = $authenticator;

		return $this;
	}

	/**
	 * Return the log instance
	 */
	public function getLog()
	{
		return $this->log;
	}

	/**
	 * Set a custom log instance
	 */
	public function setLog(Log $log)
	{
		$this->log = $log;

		return $this;
	}

	/**
	 * Shortcut methods for the current log instance
	 */

	public function log($level = LogLevel::INFO, $message, array $context = [])
	{
		return $this->getLog()->log($level, $message, $context);
	}

	public function emergency($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::EMERGENCY, $message, $context);
	}

	public function alert($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::ALERT, $message, $context);
	}

	public function critical($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::CRITICAL, $message, $context);
	}

	public function error($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::ERROR, $message, $context);
	}

	public function warning($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::WARNING, $message, $context);
	}

	public function notice($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::NOTICE, $message, $context);
	}

	public function info($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::INFO, $message, $context);
	}

	public function debug($message, array $context = [])
	{
		return $this->getLog()->log(LogLevel::DEBUG, $message, $context);
	}

	// Shortcut methods for the current timeline instance

	public function event($description, $data = [])
	{
		return $this->request->timeline()->event($description, $data);
	}

	// Shortcut methods for the Request object

	// Add database query, takes query, bindings, duration (in ms) and additional data - connection (connection name),
	// time (when was the query executed), file (caller file name), line (caller line number), trace (serialized trace),
	// model (associated ORM model)
	public function addDatabaseQuery($query, $bindings = [], $duration = null, $data = [])
	{
		return $this->getRequest()->addDatabaseQuery($query, $bindings, $duration, $data);
	}

	// Add cache query, takes type, key, value, duration (in ms) and additional data - connection (connection name),
	// time (when was the query executed), file (caller file name), line (caller line number), trace (serialized trace),
	// expiration
	public function addCacheQuery($type, $key, $value = null, $duration = null, $data = [])
	{
		return $this->getRequest()->addCacheQuery($type, $key, $value, $duration, $data);
	}

	// Add event, takes event name, data, time and additional data - listeners, file (caller file name), line (caller
	// line number), trace (serialized trace)
	public function addEvent($event, $eventData = null, $time = null, $data = [])
	{
		return $this->getRequest()->addEvent($event, $eventData, $time, $data);
	}

	// Add route, takes method, uri, action and additional data - name, middleware, before (before filters), after
	// (after filters)
	public function addRoute($method, $uri, $action, $data = [])
	{
		return $this->getRequest()->addRoute($method, $uri, $action, $data);
	}

	// Add sent email, takes subject, recipient address, sender address, array of headers, and additional data - time
	// (when was the email sent), duration (sending time in ms)
	public function addEmail($subject, $to, $from = null, $headers = [], $data = [])
	{
		return $this->getRequest()->addEmail($subject, $to, $from, $headers, $data);
	}

	// Add view, takes view name, view data and additional data - time (when was the view rendered), duration (sending
	// time in ms)
	public function addView($name, $viewData = [], $data = [])
	{
		return $this->getRequest()->addView($name, $viewData, $data);
	}

	// Add executed subrequest, takes the requested url, suvrequest Clockwork ID and additional data - path if non-default,
	// start and end time or duration in seconds to add the subrequest to the timeline
	public function addSubrequest($url, $id, $data = [])
	{
		if (isset($data['duration'])) {
			$data['end'] = microtime(true);
			$data['start'] = $data['end'] - $data['duration'];
		}

		if (isset($data['start'])) {
			$this->timeline->event("Subrequest - {$url}", [
				'name'  => "subrequest-{$id}",
				'start' => $data['start'],
				'end'   => isset($data['end']) ? $data['end'] : null
			]);
		}

		return $this->getRequest()->addSubrequest($url, $id, $data);
	}

	// DEPRECATED Use addSubrequest method
	public function subrequest($url, $id, $path = null)
	{
		return $this->getRequest()->addSubrequest($url, $id, $path);
	}

	// Add custom user data (presented as additional tabs in the official app)
	public function userData($key = null)
	{
		return $this->getRequest()->userData($key);
	}
}
