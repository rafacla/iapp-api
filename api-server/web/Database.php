<?php
class Database
{
	protected static $servername;
	protected static $username;
	protected static $password;
	protected static $database = "iapp";
	protected static $connection;
	

	public function __construct() {
		if (!file_exists("config.ini"))
			die("Não encontrei o arquivo config.ini dentro da pasta \"web\" ou você arruma ou eu paro por aqui.<br>Verifique o arquivo de exemplo chamado \"config.ini.sample\"");
		$ini_array = parse_ini_file("config.ini", true);
		self::$servername = $ini_array['mysql_host'];
		self::$username = $ini_array['mysql_user'];
		self::$password = $ini_array['mysql_password'];
		self::$connection = $this -> connect();
		self::$connection->set_charset("utf8");
	}

	public function dsn() {
		$dsn      = 'mysql:dbname='.self::$database.';host='.self::$servername;
		return ($dsn);
	}
	
	public function username() {
		return (self::$username);
	}
	
	public function password() {
		return (self::$password);
	}
	
	
	public function connect() {
		if(!isset($connection)) {
			self::$connection = new mysqli(self::$servername,self::$username,self::$password,self::$database);
		}
		
		// If connection was not successful, handle the error
		if(self::$connection === false) {
			// Handle error - notify administrator, log to a file, show an error screen, etc.
			return false; 
		}
		return self::$connection;
	}
	
	/**
	 * Query the database
	 *
	 * @param $query The query string
	 * @return mixed The result of the mysqli::query() function
	 */
	public function query($query) {
		// Connect to the database
		$result = false;
		
		if (self::$connection!=false) {
			// Query the database
			$sql = '';
			if (is_string($query)) {
				$result = self::$connection->query($query);
				
			} else {
				foreach ($query as $linha) {
					$sql .= $linha;
				}
				self::$connection->multi_query($sql);
			}
		}		
		return $result;
	}
	
	public function insert($query) {
		// Connect to the database
		$result = false;
		$i=0;
		if (self::$connection!=false) {
			// Query the database
			$sql = '';
			if (is_string($query)) {
				$query = self::$connection->query($query);
				if ($query)
					$result = self::$connection->insert_id;
				else 
					$result = false;
			} else {
				foreach ($query as $linha) {
					$sql .= $linha;
				}
				self::$connection->multi_query($sql);
				$result[$i] = self::$connection->insert_id;
				$i++;
			}
		}		
		return $result;
	}
		
	/**
	 * Fetch rows from the database (SELECT query)
	 *
	 * @param $query The query string
	 * @return bool False on failure / array Database rows on success
	 */
	public function select($query) {
		$rows = array();
		$result = $this->query($query);
		
		if($result === false) {
			return false;
		}
		while ($row = $result -> fetch_assoc()) {
			$rows[] = $row;
		}
		return $rows;
	}
		
	/**
     * Fetch the last error from the database
     * 
     * @return string Database error message
     */
    public function error() {
        return self::$connection -> error;
    }

    /**
     * Quote and escape value for use in a database query
     *
     * @param string $value The value to be quoted and escaped
     * @return string The quoted and escaped string
     */
    public function escape_string($value) {
        return  self::$connection -> real_escape_string($value);
    }
}