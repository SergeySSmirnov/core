<?php

/**
 * Kohana exception class. Translates exceptions using the [I18n] class.
 *
 * @package    Kohana
 * @category   Exceptions
 * @author     Kohana Team
 * @author     Sergey S. Smirnov
 * @copyright  (c) 2008-2012 Kohana Team
 * @license    http://kohanaframework.org/license
 */
class Kohana_Kohana_Exception extends Exception {

	/**
	 * @var  array  PHP error code => human readable name
	 */
	public static $php_errors = array(
		E_ERROR              => 'Fatal Error',
		E_USER_ERROR         => 'User Error',
		E_PARSE              => 'Parse Error',
		E_WARNING            => 'Warning',
		E_USER_WARNING       => 'User Warning',
		E_STRICT             => 'Strict',
		E_NOTICE             => 'Notice',
		E_RECOVERABLE_ERROR  => 'Recoverable Error',
		E_DEPRECATED         => 'Deprecated',
	);

	/**
	 * @var  string  error rendering view
	 */
	public static $error_view = 'kohana/error';

	/**
	 * @var  string  error view content type
	 */
	public static $error_view_content_type = 'text/html';

	/**
	 * Creates a new translated exception.
	 *
	 *     throw new Kohana_Exception('Something went terrible wrong, :user',
	 *         array(':user' => $user));
	 *
	 * @param   string          $message    error message
	 * @param   array           $variables  translation variables
	 * @param   integer|string  $code       the exception code
	 * @param   Exception       $previous   Previous exception
	 * @return  void
	 */
	public function __construct($message = "", array $variables = NULL, $code = 0, Exception $previous = NULL)
	{
		// Set the message
		$message = __($message, $variables);

		// Pass the message and integer code to the parent
		parent::__construct($message, (int) $code, $previous);

		// Save the unmodified code
		// @link http://bugs.php.net/39615
		$this->code = $code;
	}

	/**
	 * Magic object-to-string method.
	 *
	 *     echo $exception;
	 *
	 * @uses    Kohana_Exception::text
	 * @return  string
	 */
	public function __toString()
	{
		return Kohana_Exception::text($this);
	}

	/**
	 * Inline exception handler, displays the error message, source of the exception, and the stack trace of the error.
	 * @uses Kohana_Exception::response
	 * @param Exception  $e
	 * @return void
	 */
	public static function handler(Throwable $e) {
		echo Kohana_Exception::_handler($e)->send_headers()->body(); // Send the response to the browser
		exit(1);
	}

	/**
	 * Exception handler, logs the exception and generates a Response object for display.
	 * @uses Kohana_Exception::response
	 * @param Exception  $e
	 * @return Response
	 */
	public static function _handler(Throwable $e) {
		try {
			Log::logError($e, Log::EMERGENCY, 'Kohana_Exception'); // Log the exception
			return Kohana_Exception::response($e); // Generate the response
		} catch (Exception $e) { // Things are going *really* badly for us, We now have no choice but to bail. Hard...
			ob_get_level() AND ob_clean(); // Clean the output buffer if one exists
			header('Content-Type: text/plain; charset='.Kohana::$charset, TRUE, 500); // Set the Status code to 500, and Content-Type to text/plain
			echo Kohana_Exception::text($e);
			exit(1);
		}
	}

	/**
	 * Logs an exception.
	 * @uses Kohana_Exception::text
	 * @param Exception  $e
	 * @param int        $level
	 * @return void
	 */
	public static function log(Exception $e, $level = Log::EMERGENCY) {
		Log::logError($e, $level);
	}

	/**
	 * Get a single line of text representing the exception:
	 *
	 * Error [ Code ]: Message ~ File [ Line ]
	 *
	 * @param   Exception  $e
	 * @return  string
	 */
	public static function text(Exception $e)
	{
		return sprintf('%s [ %s ]: %s ~ %s [ %d ]',
			get_class($e), $e->getCode(), strip_tags($e->getMessage()), Debug::path($e->getFile()), $e->getLine());
	}

	/**
	 * Get a Response object representing the exception.
	 * @uses Kohana_Exception::text
	 * @param Throwable $e
	 * @return Response
	 */
	public static function response(Throwable $e) {
		try {
			$class = get_class($e);
			$code = $e->getCode();
			$message = $e->getMessage();
			$file = $e->getFile();
			$line = $e->getLine();
			$trace = $e->getTrace();
			if ($e instanceof HTTP_Exception AND $trace[0]['function'] == 'factory') // HTTP_Exceptions are constructed in the HTTP_Exception::factory() method. We need to remove that entry from the trace and overwrite the variables from above
				extract(array_shift($trace));
			if ($e instanceof ErrorException) {
				if (function_exists('xdebug_get_function_stack') AND $code == E_ERROR) { // If XDebug is installed, and this is a fatal error, use XDebug to generate the stack trace
					$trace = array_slice(array_reverse(xdebug_get_function_stack()), 4);
					foreach ($trace as & $frame) {
						if ( ! isset($frame['type'])) // XDebug pre 2.1.1 doesn't currently set the call type key http://bugs.xdebug.org/view.php?id=695
							$frame['type'] = '??';
						if ('dynamic' === $frame['type']) // Xdebug returns the words 'dynamic' and 'static' instead of using '->' and '::' symbols
							$frame['type'] = '->';
						elseif ('static' === $frame['type'])
							$frame['type'] = '::';
						if (isset($frame['params']) AND ! isset($frame['args'])) // XDebug also has a different name for the parameters array
							$frame['args'] = $frame['params'];
					}
				}
				if (isset(Kohana_Exception::$php_errors[$code]))
					$code = Kohana_Exception::$php_errors[$code]; // Use the human-readable error name
			}
			if ( defined('PHPUnit_MAIN_METHOD') OR defined('PHPUNIT_COMPOSER_INSTALL') OR defined('__PHPUNIT_PHAR__')) // The stack trace becomes unmanageable inside PHPUnit. The error view ends up several GB in size, taking serveral minutes to render
				$trace = array_slice($trace, 0, 2);
			$view = View::factory(Kohana_Exception::$error_view, get_defined_vars()); // Instantiate the error view
			$response = Response::factory(); // Prepare the response object
			$response->status(($e instanceof HTTP_Exception) ? $e->getCode() : 500); // Set the response status
			$response->headers('Content-Type', Kohana_Exception::$error_view_content_type.'; charset='.Kohana::$charset); // Set the response headers
			$response->body($view->render()); // Set the response body
		}
		catch (Exception $e) { // Things are going badly for us, Lets try to keep things under control by generating a simpler response object
			$response = Response::factory();
			$response->status(500);
			$response->headers('Content-Type', 'text/plain');
			$response->body(Kohana_Exception::text($e));
		}
		return $response;
	}

}
