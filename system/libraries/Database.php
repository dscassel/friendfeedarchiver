<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Provides database access in a platform agnostic way, using simple query building blocks.
 *
 * $Id: Database.php 2527 2008-04-17 20:14:37Z JAAulde $
 *
 * @package    Core
 * @author     Kohana Team
 * @copyright  (c) 2007-2008 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */
class Database_Core {

	// Global benchmark
	public static $benchmarks = array();

	// Configuration
	protected $config = array
	(
		'benchmark'     => TRUE,
		'persistent'    => FALSE,
		'connection'    => '',
		'character_set' => 'utf8',
		'table_prefix'  => '',
		'object'        => TRUE,
		'cache'         => FALSE
	);

	// Database driver object
	protected $driver;
	protected $link;

	// Un-compiled parts of the SQL query
	protected $select     = array();
	protected $set        = array();
	protected $from       = array();
	protected $join       = array();
	protected $where      = array();
	protected $orderby    = array();
	protected $order      = array();
	protected $groupby    = array();
	protected $having     = array();
	protected $distinct   = FALSE;
	protected $limit      = FALSE;
	protected $offset     = FALSE;
	protected $last_query = '';

	/**
	 * Returns a singleton instance of Database.
	 *
	 * @param   mixed   configuration array or DSN
	 * @return  object
	 */
	public static function instance($config = array())
	{
		static $instance;

		// Create the instance if it does not exist
		($instance === NULL) and $instance = new Database($config);

		return $instance;
	}

	/**
	 * Sets up the database configuration, loads the Database_Driver.
	 *
	 * @throws  Kohana_Database_Exception
	 */
	public function __construct($config = array())
	{
		if (empty($config))
		{
			// Load the default group
			$config = Config::item('database.default');
		}
		elseif (is_array($config) && count($config) > 0)
		{
			if ( ! array_key_exists('connection', $config))
			{
				$config = array('connection' => $config);
			}
		}
		elseif (is_string($config))
		{
			// The config is a DSN string
			if (strpos($config, '://') !== FALSE)
			{
				$config = array('connection' => $config);
			}
			// The config is a group name
			else
			{
				$name = $config;

				// Test the config group name
				if (($config = Config::item('database.'.$config)) === NULL)
					throw new Kohana_Database_Exception('database.undefined_group', $name);
			}
		}

		// Merge the default config with the passed config
		$this->config = array_merge($this->config, $config);

		if (is_string($this->config['connection']))
		{
			// Make sure the connection is valid
			if (strpos($this->config['connection'], '://') === FALSE)
				throw new Kohana_Database_Exception('database.invalid_dsn', $this->config['connection']);

			// Parse the DSN, creating an array to hold the connection parameters
			$db = array
			(
				'type'     => FALSE,
				'user'     => FALSE,
				'pass'     => FALSE,
				'host'     => FALSE,
				'port'     => FALSE,
				'socket'   => FALSE,
				'database' => FALSE
			);

			// Get the protocol and arguments
			list ($db['type'], $connection) = explode('://', $this->config['connection'], 2);

			if (strpos($connection, '@') !== FALSE)
			{
				// Get the username and password
				list ($db['pass'], $connection) = explode('@', $connection, 2);
				// Check if a password is supplied
				$logindata = explode(':', $db['pass'], 2);
				$db['pass'] = (count($logindata) > 1) ? $logindata[1] : '';
				$db['user'] = $logindata[0];

				// Prepare for finding the database
				$connection = explode('/', $connection);

				// Find the database name
				$db['database'] = array_pop($connection);

				// Reset connection string
				$connection = implode('/', $connection);

				// Find the socket
				if (preg_match('/^unix\([^)]++\)/', $connection))
				{
					// This one is a little hairy: we explode based on the end of
					// the socket, removing the 'unix(' from the connection string
					list ($db['socket'], $connection) = explode(')', substr($connection, 5), 2);
				}
				elseif (strpos($connection, ':') !== FALSE)
				{
					// Fetch the host and port name
					list ($db['host'], $db['port']) = explode(':', $connection, 2);
				}
				else
				{
					$db['host'] = $connection;
				}
			}
			else
			{
				// File connection
				$connection = explode('/', $connection);

				// Find database file name
				$db['database'] = array_pop($connection);

				// Find database directory name
				$db['socket'] = implode('/', $connection).'/';
			}

			// Reset the connection array to the database config
			$this->config['connection'] = $db;
		}
		// Set driver name
		$driver = 'Database_'.ucfirst($this->config['connection']['type']).'_Driver';

		// Load the driver
		if ( ! Kohana::auto_load($driver))
			throw new Kohana_Database_Exception('database.driver_not_supported', $this->config['connection']['type']);

		// Initialize the driver
		$this->driver = new $driver($this->config);

		// Validate the driver
		if ( ! ($this->driver instanceof Database_Driver))
			throw new Kohana_Database_Exception('database.driver_not_supported', 'Database drivers must use the Database_Driver interface.');

		Log::add('debug', 'Database Library initialized');
	}

	/**
	 * Simple connect method to get the database queries up and running.
	 *
	 * @return  void
	 */
	public function connect()
	{
		// A link can be a resource or an object
		if ( ! is_resource($this->link) AND ! is_object($this->link))
		{
			$this->link = $this->driver->connect();
			if ( ! is_resource($this->link) AND ! is_object($this->link))
				throw new Kohana_Database_Exception('database.connection', $this->driver->show_error());

			// Clear password after successful connect
			$this->config['connection']['pass'] = NULL;
		}
	}

	/**
	 * Runs a query into the driver and returns the result.
	 *
	 * @param   string  SQL query to execute
	 * @return  object  Database_Result
	 */
	public function query($sql = '')
	{
		if ($sql == '') return FALSE;

		// No link? Connect!
		$this->link or $this->connect();

		// Start the benchmark
		$start = microtime(TRUE);

		if (func_num_args() > 1) //if we have more than one argument ($sql)
		{
			$argv = func_get_args();
			$binds = (is_array(next($argv))) ? current($argv) : array_slice($argv, 1);
		}

		// Compile binds if needed
		if (isset($binds))
		{
			$sql = $this->compile_binds($sql, $binds);
		}

		// Fetch the result
		$result = $this->driver->query($this->last_query = $sql);

		// Stop the benchmark
		$stop = microtime(TRUE);

		if ($this->config['benchmark'] == TRUE)
		{
			// Benchmark the query
			self::$benchmarks[] = array('query' => $sql, 'time' => $stop - $start, 'rows' => count($result));
		}

		return $result;
	}

	/**
	 * Selects the column names for a database query.
	 *
	 * @param   string  string or array of column names to select
	 * @return  object  This Database object.
	 */
	public function select($sql = '*')
	{
		if (func_num_args() > 1)
		{
			$sql = func_get_args();
		}
		elseif (is_string($sql))
		{
			$sql = explode(',', $sql);
		}
		else
		{
			$sql = (array) $sql;
		}

		foreach($sql as $val)
		{
			if (($val = trim($val)) === '') continue;

			if (strpos($val, '(') === FALSE AND $val !== '*')
			{
				if (preg_match('/^DISTINCT\s++(.+)$/i', $val, $matches))
				{
					$val = 'DISTINCT '.$this->config['table_prefix'].$matches[1];
				}
				else
				{
					$val = (strpos($val, '.') !== FALSE) ? $this->config['table_prefix'].$val : $val;
				}

				$val = $this->driver->escape_column($val);
			}

			$this->select[] = $val;
		}

		return $this;
	}

	/**
	 * Selects the from table(s) for a database query.
	 *
	 * @param   string  string or array of tables to select
	 * @return  object  This Database object.
	 */
	public function from($sql)
	{
		foreach((array) $sql as $val)
		{
			if (($val = trim($val)) == '') continue;

			$this->from[] = $this->config['table_prefix'].$val;
		}

		return $this;
	}

	/**
	 * Generates the JOIN portion of the query.
	 *
	 * @param   string        table name
	 * @param   string|array  where key or array of key => value pairs
	 * @param   string        where value
	 * @param   string        type of join
	 * @return  object        This Database object.
	 */
	public function join($table, $key, $value = NULL, $type = '')
	{
		if ($type != '')
		{
			$type = strtoupper(trim($type));

			if ( ! in_array($type, array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER'), TRUE))
			{
				$type = '';
			}
			else
			{
				$type .= ' ';
			}
		}

		$cond = array();
		$keys  = is_array($key) ? $key : array($key => $value);
		foreach ($keys as $key => $value)
		{
			$key    = (strpos($key, '.') !== FALSE) ? $this->config['table_prefix'].$key : $key;
			$cond[] = $this->driver->where($key, $this->driver->escape_column($this->config['table_prefix'].$value), 'AND ', count($cond), FALSE);
		}

		$this->join[] = $type.'JOIN '.$this->driver->escape_column($this->config['table_prefix'].$table).' ON '.implode(' ', $cond);

		return $this;
	}

	/**
	 * Selects the where(s) for a database query.
	 *
	 * @param   string|array  key name or array of key => value pairs
	 * @param   string        value to match with key
	 * @param   boolean       disable quoting of WHERE clause
	 * @return  object        This Database object.
	 */
	public function where($key, $value = NULL, $quote = TRUE)
	{
		$quote = (func_num_args() < 2 AND ! is_array($key)) ? -1 : $quote;
		$keys  = is_array($key) ? $key : array($key => $value);

		foreach ($keys as $key => $value)
		{
			$key           = (strpos($key, '.') !== FALSE) ? $this->config['table_prefix'].$key : $key;
			$this->where[] = $this->driver->where($key, $value, 'AND ', count($this->where), $quote);
		}

		return $this;
	}

	/**
	 * Selects the or where(s) for a database query.
	 *
	 * @param   string|array  key name or array of key => value pairs
	 * @param   string        value to match with key
	 * @param   boolean       disable quoting of WHERE clause
	 * @return  object        This Database object.
	 */
	public function orwhere($key, $value = NULL, $quote = TRUE)
	{
		$quote = (func_num_args() < 2 AND ! is_array($key)) ? -1 : $quote;
		$keys  = is_array($key) ? $key : array($key => $value);

		foreach ($keys as $key => $value)
		{
			$key           = (strpos($key, '.') !== FALSE) ? $this->config['table_prefix'].$key : $key;
			$this->where[] = $this->driver->where($key, $value, 'OR ', count($this->where), $quote);
		}

		return $this;
	}

	/**
	 * Selects the like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function like($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->like($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the or like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function orlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->like($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the not like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function notlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notlike($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the or not like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function ornotlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notlike($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function regex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->regex($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the or like(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        like value to match with field
	 * @return  object        This Database object.
	 */
	public function orregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->regex($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the not regex(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        regex value to match with field
	 * @return  object        This Database object.
	 */
	public function notregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notregex($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Selects the or not regex(s) for a database query.
	 *
	 * @param   string|array  field name or array of field => match pairs
	 * @param   string        regex value to match with field
	 * @return  object        This Database object.
	 */
	public function ornotregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notregex($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Chooses the column to group by in a select query.
	 *
	 * @param   string  column name to group by
	 * @return  object  This Database object.
	 */
	public function groupby($by)
	{
		if ( ! is_array($by))
		{
			$by = explode(',', (string) $by);
		}

		foreach ($by as $val)
		{
			$val = trim($val);

			if ($val != '')
			{
				$this->groupby[] = $val;
			}
		}

		return $this;
	}

	/**
	 * Selects the having(s) for a database query.
	 *
	 * @param   string|array  key name or array of key => value pairs
	 * @param   string        value to match with key
	 * @param   boolean       disable quoting of WHERE clause
	 * @return  object        This Database object.
	 */
	public function having($key, $value = '', $quote = TRUE)
	{
		$this->having[] = $this->driver->where($key, $value, 'AND', count($this->having), TRUE);
		return $this;
	}

	/**
	 * Selects the or having(s) for a database query.
	 *
	 * @param   string|array  key name or array of key => value pairs
	 * @param   string        value to match with key
	 * @param   boolean       disable quoting of WHERE clause
	 * @return  object        This Database object.
	 */
	public function orhaving($key, $value = '', $quote = TRUE)
	{
		$this->having[] = $this->driver->where($key, $value, 'OR', count($this->having), TRUE);
		return $this;
	}

	/**
	 * Chooses which column(s) to order the select query by.
	 *
	 * @param   string|array  column(s) to order on, can be an array, single column, or comma seperated list of columns
	 * @param   string        direction of the order
	 * @return  object        This Database object.
	 */
	public function orderby($orderby, $direction = '')
	{
		$direction = strtoupper(trim($direction));

		if ($direction != '')
		{
			$direction = (in_array($direction, array('ASC', 'DESC', 'RAND()', 'NULL'))) ? ' '.$direction : ' ASC';
		}

		if (empty($orderby))
		{
			$this->orderby[] = $direction;
			return $this;
		}

		if ( ! is_array($orderby))
		{
			$orderby = explode(',', (string) $orderby);
		}

		$order = array();
		foreach ($orderby as $field)
		{
			$field = trim($field);

			if ($field != '')
			{
				$order[] = $this->driver->escape_column($field);
			}
		}
		$this->orderby[] = implode(',', $order).$direction;
		return $this;
	}

	/**
	 * Selects the limit section of a query.
	 *
	 * @param   integer  number of rows to limit result to
	 * @param   integer  offset in result to start returning rows from
	 * @return  object   This Database object.
	 */
	public function limit($limit, $offset = FALSE)
	{
		$this->limit  = (int) $limit;
		$this->offset(($offset === FALSE)? 0 : $offset);

		return $this;
	}

	/**
	 * Sets the offset portion of a query.
	 *
	 * @param   integer  offset value
	 * @return  object   This Database object.
	 */
	public function offset($value)
	{
		$this->offset = (int) $value;

		return $this;
	}

	/**
	 * Allows key/value pairs to be set for inserting or updating.
	 *
	 * @param   string|array  key name or array of key => value pairs
	 * @param   string        value to match with key
	 * @return  object        This Database object.
	 */
	public function set($key, $value = '')
	{
		if ( ! is_array($key))
		{
			$key = array($key => $value);
		}

		foreach ($key as $k => $v)
		{
			$this->set[$k] = $this->driver->escape($v);
		}

		return $this;
	}

	/**
	 * Compiles the select statement based on the other functions called and runs the query.
	 *
	 * @param   string  table name
	 * @param   string  limit clause
	 * @param   string  offset clause
	 * @return  object  Database_Result
	 */
	public function get($table = '', $limit = NULL, $offset = NULL)
	{
		if ($table != '')
		{
			$this->from($table);
		}

		if ( ! is_null($limit))
		{
			$this->limit($limit, $offset);
		}

		$sql = $this->driver->compile_select(get_object_vars($this));

		$result = $this->query($sql);

		$this->reset_select();
		$this->last_query = $sql;

		return $result;
	}

	/**
	 * Compiles the select statement based on the other functions called and runs the query.
	 *
	 * @param   string  table name
	 * @param   array   where clause
	 * @param   string  limit clause
	 * @param   string  offset clause
	 * @return  object  This Database object.
	 */
	public function getwhere($table = '', $where = NULL, $limit = NULL, $offset = NULL)
	{
		if ($table != '')
		{
			$this->from($table);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		if ( ! is_null($limit))
		{
			$this->limit($limit, $offset);
		}

		$sql = $this->driver->compile_select(get_object_vars($this));

		$result = $this->query($sql);
		$this->reset_select();
		return $result;
	}

	/**
	 * Compiles an insert string and runs the query.
	 *
	 * @param   string  table name
	 * @param   array   array of key/value pairs to insert
	 * @return  object  This Database object.
	 */
	public function insert($table = '', $set = NULL)
	{
		if ( ! is_null($set))
		{
			$this->set($set);
		}

		if ($this->set == NULL)
			throw new Kohana_Database_Exception('database.must_use_set');

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
				throw new Kohana_Database_Exception('database.must_use_table');

			$table = $this->from[0];
		}

		$sql = $this->driver->insert($this->config['table_prefix'].$table, array_keys($this->set), array_values($this->set));

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Compiles a merge string and runs the query.
	 *
	 * @param   string  table name
	 * @param   array   array of key/value pairs to merge
	 * @return  object  This Database object.
	 */
	public function merge($table = '', $set = NULL)
	{
		if ( ! is_null($set))
		{
			$this->set($set);
		}

		if ($this->set == NULL)
			throw new Kohana_Database_Exception('database.must_use_set');

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
				throw new Kohana_Database_Exception('database.must_use_table');

			$table = $this->from[0];
		}

		$sql = $this->driver->merge($this->config['table_prefix'].$table, array_keys($this->set), array_values($this->set));

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Compiles an update string and runs the query.
	 *
	 * @param   string  table name
	 * @param   array   associative array of update values
	 * @param   array   where clause
	 * @return  object  This Database object.
	 */
	public function update($table = '', $set = NULL, $where = NULL)
	{
		if ( is_array($set))
		{
			$this->set($set);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		if ($this->set == FALSE)
			throw new Kohana_Database_Exception('database.must_use_set');

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
				throw new Kohana_Database_Exception('database.must_use_table');

			$table = $this->from[0];
		}

		$sql = $this->driver->update($this->config['table_prefix'].$table, $this->set, $this->where);

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Compiles a delete string and runs the query.
	 *
	 * @param   string  table name
	 * @param   array   where clause
	 * @return  object  This Database object.
	 */
	public function delete($table = '', $where = NULL)
	{
		if ($table == '')
		{
			if ( ! isset($this->from[0]))
				throw new Kohana_Database_Exception('database.must_use_table');

			$table = $this->from[0];
		}

		if (! is_null($where))
		{
			$this->where($where);
		}

		if (count($this->where) < 1)
			throw new Kohana_Database_Exception('database.must_use_where');

		$sql = $this->driver->delete($this->config['table_prefix'].$table, $this->where);

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Returns the last query run.
	 *
	 * @return  string
	 */
	public function last_query()
	{
	   return $this->last_query;
	}

	/**
	 * Count query records.
	 *
	 * @param   string   table name
	 * @param   array    where clause
	 * @return  integer
	 */
	public function count_records($table = FALSE, $where = NULL)
	{
		if (count($this->from) < 1)
		{
			if ($table == FALSE)
				throw new Kohana_Database_Exception('database.must_use_table');

			$this->from($table);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		$query = $this->select('COUNT(*) AS records_found')->get()->result(TRUE);

		return $query->current()->records_found;
	}

	/**
	 * Resets all private select variables.
	 *
	 * @return  void
	 */
	private function reset_select()
	{
		$this->select   = array();
		$this->from     = array();
		$this->join     = array();
		$this->where    = array();
		$this->orderby  = array();
		$this->groupby  = array();
		$this->having   = array();
		$this->distinct = FALSE;
		$this->limit    = FALSE;
		$this->offset   = FALSE;
	}

	/**
	 * Resets all private insert and update variables.
	 *
	 * @return  void
	 */
	private function reset_write()
	{
		$this->set   = array();
		$this->from  = array();
		$this->where = array();
	}

	/**
	 * Lists all the tables in the current database.
	 *
	 * @return  array
	 */
	public function list_tables()
	{
		$this->link or $this->connect();

		$this->reset_select();

		return $this->driver->list_tables();
	}

	/**
	 * See if a table exists in the database.
	 *
	 * @param   string   table name
	 * @return  boolean
	 */
	public function table_exists($table_name)
	{
		return in_array($this->config['table_prefix'].$table_name, $this->list_tables());
	}

	/**
	 * Combine a SQL statement with the bind values. Used for safe queries.
	 *
	 * @param   string  query to bind to the values
	 * @param   array   array of values to bind to the query
	 * @return  string
	 */
	public function compile_binds($sql, $binds)
	{
		foreach ((array) $binds as $val)
		{
			// If the SQL contains no more bind marks ("?"), we're done.
			if (($next_bind_pos = strpos($sql, '?')) === FALSE)
				break;

			// Properly escape the bind value.
			$val = $this->driver->escape($val);

			// Temporarily replace possible bind marks ("?"), in the bind value itself, with a placeholder.
			$val = str_replace('?', '{%B%}', $val);

			// Replace the first bind mark ("?") with its corresponding value.
			$sql = substr($sql, 0, $next_bind_pos).$val.substr($sql, $next_bind_pos + 1);
		}

		// Restore placeholders.
		return str_replace('{%B%}', '?', $sql);
	}

	/**
	 * Get the field data for a database table, along with the field's attributes.
	 *
	 * @param   string  table name
	 * @return  array
	 */
	public function field_data($table = '')
	{
		$this->link or $this->connect();

		return $this->driver->field_data($this->config['table_prefix'].$table);
	}

	/**
	 * Get the field data for a database table, along with the field's attributes.
	 *
	 * @param   string  table name
	 * @return  array
	 */
	public function list_fields($table = '')
	{
		$this->link or $this->connect();

		return $this->driver->list_fields($this->config['table_prefix'].$table);
	}

	/**
	 * Escapes a value for a query.
	 *
	 * @param   mixed   value to escape
	 * @return  string
	 */
	public function escape($value)
	{
		return $this->driver->escape($value);
	}

	/**
	 * Escapes a string for a query.
	 *
	 * @param   string  string to escape
	 * @return  string
	 */
	public function escape_str($str)
	{
		return $this->driver->escape_str($str);
	}

	/**
	 * Escapes a table name for a query.
	 *
	 * @param   string  string to escape
	 * @return  string
	 */
	public function escape_table($table)
	{
		return $this->driver->escape_table($table);
	}

	/**
	 * Escapes a column name for a query.
	 *
	 * @param   string  string to escape
	 * @return  string
	 */
	public function escape_column($table)
	{
		return $this->driver->escape_column($table);
	}

	/**
	 * Returns table prefix of current configuration.
	 *
	 * @return  string
	 */
	public function table_prefix()
	{
		return $this->config['table_prefix'];
	}

	/**
	 * Clears the query cache.
	 *
	 * @param   string|TRUE  clear cache by SQL statement or TRUE for last query
	 * @return  object       This Database object.
	 */
	public function clear_cache($sql = NULL)
	{
		if ($sql === TRUE)
		{
			$this->driver->clear_cache($this->last_query);
		}
		elseif (is_string($sql))
		{
			$this->driver->clear_cache($sql);
		}
		else
		{
			$this->driver->clear_cache();
		}

		return $this;
	}

	/**
	 * Create a prepared statement (experimental).
	 *
	 * @param   string  SQL query
	 * @return  object
	 */
	public function stmt_prepare($sql)
	{
		return $this->driver->stmt_prepare($sql, $this->config);
	}

} // End Database Class


/**
 * Sets the code for a Database exception.
 */
class Kohana_Database_Exception extends Kohana_Exception {

	protected $code = E_DATABASE_ERROR;

} // End Kohana Database Exception