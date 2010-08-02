<?php
	/**
	* PHP Process Class
	*
	* @author Eric Muyser
	*
	* Credits to Jeremy for the Super Closure Class
	*
	* Super Closure Class
	*
	* The SuperClosure class encapsulates a PHP Closure and adds new capabilities
	* like serialization and code retrieval. It uses the ReflectionFunction class
	* heavily to acquire information about the closure.
	* @author Jeremy Lindblom
	* @copyright (c) 2010 Synapse Studios, LLC.
	*/

	//ini_set('register_argc_argv', 1);

	class process_master
	{
		public function __construct($file)
		{
			$this->busy = false;
			$this->child_pid = NULL;

			$php_command = "php5";

			$descriptor = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));

			$this->proc = proc_open($php_command . " -q " . $file, $descriptor, $this->pipes);

			stream_set_blocking($this->pipes[0], false);
			stream_set_blocking($this->pipes[1], false);

			$this->kill = NULL;
		}

		public function __destruct()
		{
			$this->quit();
		}

		public function is_active()
		{
			if(!$this->proc)
				return false;

			if($this->child_pid == NULL)
				return true; // still getting pid

			//$f = stream_get_meta_data($this->pipes[1]);

			//return !$f['eof'];

			//return posix_kill($this->child_pid, 0) != false;

			if(!file_exists("/proc/{$this->child_pid}"))
				$this->kill = time() + 1500;

			if($this->kill != NULL && time() >= $this->kill)
				return false;

			return true;
		}

		public function send($command, $data = NULL, $callback = NULL)
		{
			if(!$this->is_active())
				return -1;

		   	$msg = $command . "|" . base64_encode(serialize($data)) . "|" . base64_encode(serialize($callback)) . "\n";

			fwrite($this->pipes[0], $msg);

			return 1;
		}

		public function get_messages()
		{
			return $this->process_input($this->receive_input());
		}

		public function receive_input()
		{
			$buffer = '';

			while($r = @fgets($this->pipes[1]))
				$buffer .= $r;

			return $buffer;
		}

		public function process_input($input_list)
		{
			$message_list = array();

			if(!$input_list)
				return $message_list;

			$input_list = explode("\n", $input_list);

			foreach($input_list as $input)
			{
				if(!$input)
					continue;

				$parts = explode("|", $input);

				if(count($parts) == 3)
				{
					$status = intval($parts[0]);
					$data = unserialize(base64_decode($parts[1]));
					$callback = unserialize(base64_decode($parts[2]));
				}
				else
				{
					$status = 4;
					$data = $input;
					$callback = NULL;
				}

				$message_list[] = array("status" => $status, "data" => $data, "callback" => $callback);
			}

			return $message_list;
		}

		public function quit()
		{
			if(!$this->proc)
				return;

			$this->send(2);

			//proc_close($this->proc);

			//$this->proc = NULL;

			//posix_kill($this->child_pid, 9); // just incase
		}

		public $success_handler;
		public $failure_handler;
		public $child_pid;
		private $proc;
		private $pipes;
		public $busy;
		public $kill;
	}

	class process_slave
	{
		public function __construct()
		{
			$this->parent_pid = NULL;
			$this->stdin = fopen("php://stdin", "r");

			stream_set_blocking($this->stdin, false);

			$this->send(1, posix_getpid());
		}

		public function send($status, $data = NULL, $callback = NULL)
		{
		   	$msg = $status . "|" . base64_encode(serialize($data)) . "|" . base64_encode(serialize($callback)) . "\n";

			echo $msg;

			return 1;
		}

		public function get_messages()
		{
			return $this->process_input($this->receive_input());
		}

		public function receive_input($wait = true)
		{
			$buffer = '';

			if($wait)
				while($r = fgets($this->stdin))
					$buffer .= $r;
			else
				$buffer = fgets($this->stdin);

			return $buffer;
		}

		public function process_input($input_list)
		{
			$message_list = array();

			if(!$input_list)
				return $message_list;

			$input_list = explode("\n", $input_list);

			foreach($input_list as $input)
			{
				if(!$input)
					continue;

				$parts = explode("|", $input);

				if(count($parts) != 3)
					continue;

				$status = intval($parts[0]);
				$data = unserialize(base64_decode($parts[1]));
				$callback = unserialize(base64_decode($parts[2]));

				$message_list[] = array("status" => $status, "data" => $data, "callback" => $callback);
			}

			return $message_list;
		}

		public function main($message_list)
		{
			foreach($message_list as $message)
			{
				$status = $message['status'];
				$data = $message['data'];
				$callback = $message['callback'];

				switch($status)
				{
					case 1:
						$this->parent_pid = $data;
					break;

					case 2:
						$this->send(2);

						exit(0);
					break;

					case 3:
						$result = $data();

						$this->send(3, $result, $callback);
					break;
				}
			}
		}

		public function is_active()
		{
			// if this isn't linux don't check it
			if(!stristr(PHP_OS, "linux"))
				return true;

			$loads = @exec("uptime");

			preg_match("/averages?: ([0-9\.]+),[\s]+([0-9\.]+),[\s]+([0-9\.]+)/", $loads, $x1);

			$cpu = intval($x1[1]);

			if($cpu > 80)
				return false;

			if($this->parent_pid != NULL)
				return (posix_kill($this->parent_pid, 0) != false);

			return true;
		}

		public function quit()
		{
			exit;
		}

		public function debug($data)
		{
			fwrite($this->stderr, $data);
		}

		protected $stdin;
		protected $stdout;
		protected $parent_pid;
		protected $last_callback;
	}

class resource_service
{
	public static function register(&$resource)
	{
		$id = rand(0, 32768);

		while(isset(self::$registry[$id]))
			$id = rand(0, 32768);

		self::$registry[$id] = &$resource;

		return $id;
	}

	public static function unregister($id)
	{
		$resource = &self::$registry[$id];

		unset(self::$registry[$id]);

		return $resource;
	}

	private static $registry;
}

class process_callback
{
	public function __construct(Closure $closure)
	{
		$this->closure = $closure;
	}

	public function __invoke()
	{
		return call_user_func_array($this->closure, func_get_args());
	}

	public function __sleep()
	{
		if($this->closure)
			$this->resource_id = resource_service::register($this->closure);

		return array("resource_id");
	}

	public function __wakeup()
	{
		if($closure = resource_service::unregister($this->resource_id))
			$this->closure = &$closure;
	}

	private $closure;
}

class process_request
{
	public function __construct($function)
	{
		if ( ! $function instanceOf Closure)
			throw new InvalidArgumentException();

		$this->closure = $function;
		$this->reflection = new ReflectionFunction($function);
		$this->code = $this->_fetchCode();
		$this->used_variables = $this->_fetchUsedVariables();
	}

	public function __invoke()
	{
		$args = func_get_args();
		return $this->reflection->invokeArgs($args);
	}

	public function getClosure()
	{
		return $this->closure;
	}

	protected function _fetchCode()
	{
		// Open file and seek to the first line of the closure
		$file = new SplFileObject($this->reflection->getFileName());
		$file->seek($this->reflection->getStartLine()-1);

		// Retrieve all of the lines that contain code for the closure
		$code = '';
		while ($file->key() < $this->reflection->getEndLine())
		{
			$code .= $file->current();
			$file->next();
		}

		// Only keep the code defining that closure
		$begin = strpos($code, 'function');
		$end = strrpos($code, '}');
		$code = substr($code, $begin, $end - $begin + 1);

		return $code;
	}

	public function getCode()
	{
		return $this->code;
	}

	public function getParameters()
	{
		return $this->reflection->getParameters();
	}

	protected function _fetchUsedVariables()
	{
		// Make sure the use construct is actually used
		$use_index = stripos($this->code, 'use');
		if ( ! $use_index)
			return array();

		// Get the names of the variables inside the use statement
		$begin = strpos($this->code, '(', $use_index) + 1;
		$end = strpos($this->code, ')', $begin);
		$vars = explode(',', substr($this->code, $begin, $end - $begin));

		// Get the static variables of the function via reflection
		$static_vars = $this->reflection->getStaticVariables();

		// Only keep the variables that appeared in both sets
		$used_vars = array();
		foreach ($vars as $var)
		{
			$var = trim($var, ' $&amp;');

			if(isset($var) && isset($static_vars[$var]))
				$used_vars[$var] = $static_vars[$var];
		}

		return $used_vars;
	}

	public function getUsedVariables()
	{
		return $this->used_variables;
	}

	public function __sleep()
	{
		return array('code', 'used_variables');
	}

	public function __wakeup()
	{
		extract($this->used_variables);

		eval('$_function = '.$this->code.';');
		if (isset($_function) AND $_function instanceOf Closure)
		{
			$this->closure = $_function;
			$this->reflection = new ReflectionFunction($_function);
		}
		else
			throw new Exception();
	}

	protected $closure = NULL;
	protected $reflection = NULL;
	protected $code = NULL;
	protected $used_variables = array();
}

class process_service
{
	public function __construct()
	{
		$this->process_list = array();
	}

	public function total_active()
	{
		$total = 0;

		$total = count($this->process_list);

		return $total;
	}

	public function run($request, $callback)
	{
		$process = NULL;

		if(!$process)
		{
			$process = new process_master(__FILE__ . " run_process");

			$this->process_list[] = $process;
		}

		$process->busy = true;

		$process->send(3, new process_request($request), new process_callback(function($data) use(&$process, &$callback)
		{
			$process->busy = false;

			if($callback)
				return $callback($data);
		}));
	}

	public function remove_process($key)
	{
		$process = array_splice($this->process_list, $key, 1);

		if(!$process)
			return;

		$process = $process[0];

//echo "remove process: {$process->child_pid}\n";

		$process->quit();
	}

	public function check_message($process)
	{
			if($r1 = $process->get_messages())
			{
				foreach($r1 as $r2)
				{
					switch($r2['status'])
					{
						case 1:
							$process->child_pid = $r2['data'];
//echo "got pid: {$process->child_pid}\n";
							$process->send(1, posix_getpid());
						break;

						case 2:
//echo "child exited: {$process->child_pid}\n";
						break;

						case 3:
//echo "got response: {$process->child_pid}\n";
							if($r2['callback'])
								if($r2['callback']($r2['data']))
									$process->quit();
						break;

						case 4:
							echo "Process encountered errors: " . $r2['data'] . "\n";

							if($r2['callback'])
								if($r2['callback']($r2['data']))
									$process->quit();
						break;
					}
				}
			}
	}

	public function update()
	{
		foreach($this->process_list as $key => $process)
		{
			if(!$process->is_active())
			{
				$this->check_message($process); // residual messages

				$this->remove_process($key);
			}
		}

		foreach($this->process_list as $key => $process)
		{
			$this->check_message($process);
		}
	}

	public $process_list;
}

// testing
if(isset($argv) && in_array("run_process", $argv))
{
	//ini_set('display_errors', 0);
	//error_reporting(E_ALL);

	$process = new process_slave();

	function error_handler($errno, $errstr, $errfile, $errline, $errcontext)
	{
		global $process;

		if($errno != E_ERROR)
			return;

		$result = $errfile . " on line " . $errline . ": " . $errstr;

		$process->send(4, $result);
	}

	set_error_handler('error_handler');

	function exc_handler($exception)
	{
		$result = $exception->getMessage();

		$process->send(4, $result);
	}

	set_exception_handler('exc_handler');

	do
	{
		sleep(1);

		$process->main($process->get_messages());
	}
	while($process->is_active());

	$this->send(2);
}

?>
