<?php
class Database
{
	protected static $servername = "localhost";
	protected static $local_servername = "localhost";
	protected static $remote_servername = "mysql762.umbler.com";
	protected static $username;
	protected static $password;
	protected static $database = "iapp";
	
	protected static $connection;
	
	public function __construct() {
		if (!file_exists("config.ini"))
			die("Não encontrei o arquivo config.ini dentro da pasta \"web\" ou você arruma ou eu paro por aqui.<br>Verifique o arquivo de exemplo chamado \"config.ini.sample\"");
		$ini_array = parse_ini_file("config.ini", true);
		self::$username = $ini_array['mysql_user'];
		self::$password = $ini_array['mysql_password'];
	}

	public function dsn() {
		if ($_SERVER['HTTP_HOST'] == "api.localhost") {
			$servername = self::$local_servername;
		} else {
			$servername = self::$remote_servername;
		}
		$dsn      = 'mysql:dbname='.self::$database.';host='.$servername;
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
			if ($_SERVER['HTTP_HOST'] == "api.localhost") {
			$servername = self::$local_servername;
		} else {
			$servername = self::$remote_servername;
		}
			self::$connection = new mysqli($servername,self::$username,self::$password,self::$database);
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
		$connection = $this -> connect();
		$connection->set_charset("utf8");
		$result = false;
		
		if ($connection!=false) {
			// Query the database
			$sql = '';
			if (is_string($query)) {
				$result = $connection->query($query);
				
			} else {
				foreach ($query as $linha) {
					$sql .= $linha;
				}
				$connection->multi_query($sql);
			}
		}		
		return $result;
	}
	
	public function insert($query) {
		// Connect to the database
		$connection = $this -> connect();
		$connection->set_charset("utf8");
		$result = false;
		$i=0;
		if ($connection!=false) {
			// Query the database
			$sql = '';
			if (is_string($query)) {
				$query = $connection->query($query);
				if ($query)
					$result = $connection->insert_id;
				else 
					$result = false;
			} else {
				foreach ($query as $linha) {
					$sql .= $linha;
				}
				$connection->multi_query($sql);
				$result[$i] = $connection->insert_id;
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
		
		$result = $this -> query($query);
		
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
        $connection = $this -> connect();
        return $connection -> error;
    }

    /**
     * Quote and escape value for use in a database query
     *
     * @param string $value The value to be quoted and escaped
     * @return string The quoted and escaped string
     */
    public function quote($value) {
        $connection = $this -> connect();
        return "'" . $connection -> real_escape_string($value) . "'";
    }
}