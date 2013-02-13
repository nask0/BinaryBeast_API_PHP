<?php

/**
 * This class is used to cache API Request results
 * 
 * drastically cuts down on API requests that you need to make, without having to do any
 *      extra coding
 * 
 * It can cache anything that you could get through $bb->call
 * 
 * @version 1.0.2
 * @date 2013-02-12
 * @author Brandon Simmons
 */
class BBCache {

    /************************* DEVELOPERS: SET THE VALUES BELOW! ********************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************/

    /**
     * Database type, currently supported values: 'mysql' ('postgresql' next probably)
     * @var string
     */
    private $type = 'mysql';//null;
    /**
     * Server to connect to, ie 'localhost', 'mydb.site.com', etc
     * @var string
     */
    private $server = 'localhost';//null;
    /**
     * Optional port to use when connecting to $server
     *      If not provided, we will use the default port
     *      based on the database $type defined
     * @var int
     */
    private $port = null;
    /**
     * Database name
     * @var string
     */
    private $database = 'test';//null;
    /**
     * Name of the table to use
     *      This class will create the table, since we expect it to be in a certain format
     * @var string
     */
    private $table = 'bb_api_cache';
    /**
     * Username for logging into the database
     * @var string
     */
    private $username = 'test_user';//null;
    /**
     * Password for logging into the database
     * @var string
     */
    private $password = 'test';//null;

    /************************* DEVELOPERS: SET THE VALUES ABOVE! ********************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************
     ********************************************************************************/

    /** @var PDO */
    private static $static_db;
    /**
     * Referenc to static $db simply because I wanted to.
     * @var PDO
     */
    private $db;
    //For standardized name shared across all instances
    private static $static_type;

    /** @var BinaryBeast */
    private $bb;

    /**
     * DSN Prefix values for each database type
     */
    private $dsn_prefix = array('mysql' => 'mysql'/*, 'postgres' => 'pgsql', 'postgresql' => 'pgsql'*/);

    /**
     * Default ports for each database type
     * (keyed by dns_prefix)
     */
    private $db_ports = array('mysql' => 3306, 'pgsql' => 5432);

    /**
     * After successfully connecting and checking for the existance
     */
    private static $connected = false;

    /**
     * PDO connection options per db type
     * (keyed by dns_prefix)
     */
    private $pdo_options = array('mysql' => array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'), 'pgsql' => array());

    /**
     * Each record can have an object associated with it, and we identify the object type
     *  by an integer - here are some constants for their values
     */
    const TYPE_TOURNAMENT           = 0;
    const TYPE_TEAM                 = 1;

    /**
     * Constructor
     * Stores local references to the API library, and the database connection
     * 
     * @param BinaryBeast   $bb
     */
    function __construct(BinaryBeast &$bb) {
        $this->bb   = $bb;

        /**
         * Already connected :)
         * store a reference to the static $db in $this->db, for cleaner code / less hair pullage
         * also make sure that this instance's $type value is lower case
         */
        if(self::$connected) {
            $this->type = self::$static_type;
            $this->db = &self::$static_db;
            return;
        }

        /**
         * If the class hasn't been configured with any values at all, we'll simply fail
         *      silently
         * 
         * However if we DO have values, try to connect now, and call $bb->set_error if 
         *      we encounter any problems
         */
        if(self::check_values()) {
            if(($error = $this->connect()) !== true) {
                $bb->set_error($error, 'BBCache');
            }
        }
    }

    /**
     * Simply returns a boolean to indicate whether or not
     *  all required values have been defined, becauase we'll
     *  simply fail silently if not configured
     */
    private function check_values() {
        if(is_null($this->type)
            || is_null($this->server)
            || is_null($this->database)
            || is_null($this->table)
            || is_null($this->username)
            || is_null($this->password) ) {
            return false;
        }
        //Success! Make sure $type is all lower case to be standard, and return true
        self::$static_type = strtolower($this->type);
        $this->type = self::$static_type;
        return true;
    }

    /**
     * Attempt to connect to the database
     * If any errors encounted while connecting, we will return 
     *  the error message,
     *  otherwise if we're successful, we return true
     * 
     * So evaluate the result using === true
     */
    private function connect() {
        //Determine the DSN prefix and port
        if(!isset($this->dsn_prefix[$this->type])) {
            return 'Invalid database type: ' . $this->type;
        }
        else {
            $dsn_prefix = $this->dsn_prefix[$this->type];
        }

        //Use default port if not specified
        if(is_null($this->port))    $port = $this->db_ports[$dsn_prefix];
        else                        $port = $this->port;

        /**
         * Make sure PDO for our database type is enabled
         * This is done AFTER calculating the dsn_prefix, because the 
         *  dsn_prefix happens to be named the same as the extension we need
         */
        if(!extension_loaded('pdo_' . $dsn_prefix)) {
            return 'pdo_' . $dsn_prefix . ' not enabled/installed!';
        }

        //Try to establish the connection, and store it staticly
        try {
            self::$static_db = new PDO("$dsn_prefix:host=" . $this->server . ';dbname=' . $this->database . ';port=' . $port,
                $this->username, $this->password, $this->pdo_options[$dsn_prefix]
            );
        } catch(PDOException $error) {
            return 'Error connecting to the database (' . $error->getMessage() . ')';
        }

        //Store an instance reference to the new PDO object, and set the connection flag to true
        $this->db = &self::$static_db;

        //Success! Now, make sure the table exists, create it if not
        if(!$this->check_table()) {
            if(!$this->create_table()) {
                return $this->db->errorInfo();
                return 'Error creating the table "' . $this->table . '", please make sure user "' . $this->username . '" has permission to create tables on database "' . $this->database . '"';
            }
        }

        //Success!
        self::$connected = true;
        return true;
    }

    /**
     * Check to see if our $table exists in the database
     * @return boolean
     */
    private function check_table() {
        return $this->db->query("SELECT COUNT(*) FROM {$this->table}") !== false;
    }
    /**
     * Attempt to create the table 
     * @return boolean
     */
    private function create_table() {
        return $this->db->exec("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
            `id`                int(10)         unsigned NOT NULL AUTO_INCREMENT,
            `service`           varchar(100)    NOT NULL,
            `object_type`       int(4)          unsigned NULL DEFAULT NULL,
            `object_id`         varchar(50)     NULL DEFAULT NULL,
            `result`            text            NOT NULL,
            `expires`           datetime        NOT NULL,
            PRIMARY KEY         (`id`),
            UNIQUE KEY          (`service`,`object_type`,`object_id`),
            KEY `expires`       (`expires`),
            KEY `object`        (`object_type`,`object_id`),
            KEY `object_type`   (`object_type`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ") !== false;
    }

    /**
     * Checks to see if this class has successfully connected and logged in yet
     * @return boolean
     */
    public function connected() {
        return self::$connected;
    }

    /**
     * As the name indicates, this method will delete any records that have expired, forcing new API calls when requested again
     * @return boolean
     */
    public function clear_expired() {
        return $this->db->exec("
            DELETE FROM {$this->table}
            WHERE TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires) <= 0
        ") !== false;
    }
    /**
     * Clears services associated with the provided service name, object type, object id, or any
     *      combination of them (for example all cached result of a certain service associated with any tournament)
     * 
     * If nothing at all was provided, ALL cache will be deleted
     * 
     * @param string    $svc
     * @param int       $object_type
     * @param string    $object_id
     * @return boolean
     */
    public function clear($svc = null, $object_type = null, $object_id = null) {
        //Build the WHERE query
        $where = $this->build_where($svc, $object_type, $object_id);

        //GOGOGO!!!
        return $this->db->exec("
            DELETE FROM {$this->table} $where
        ") !== false;
    }

    /**
     * Can be used in place of $bb->call, this method will check the local
     * cache table for any results from previous identical calls
     * 
     * It does not match arguments, but it matches tourney_id or tourney_team_id with the service
     * 
     * @param string    $svc
     * @param array     $args
     * @param int       $ttl                In minutes, how long to keep the result as valid
     * @param int       $object_type        Tournament, game, etc - use BBCache::TYPE_ constants for values
     * @param int       $tourney_team_id 
     * @param string    $game_code
     * 
     * @return boolean
     */
    public function call($svc, $args = null, $ttl = null, $object_type = null, $object_id = null) {
        //Build the WHERE clause to try to find a cacheed response in the local database
        $where = $this->build_where($svc, $object_type, $object_id);

        //First step - try to find an already cached response - if expired, remember the ID and we'll update later
        $id = null;
        $result = $this->db->query("
            SELECT id, result, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires) AS minutes_remaining
            FROM {$this->table}
            $where
        ");

        //Found it! is ist still valid??
        if($result->rowCount() > 0) {
            $row = $result->fetchObject();

            //Success!
            if(intval($row->minutes_remaining) > 0) {
                //Add a value "from_cache" just FYI
                $result = $this->decompress($row->result);
                $result->from_cache = true;
                return $result;
            }
            else $id = $row->id;
        }

        //We don't have a valid cached response, call the API now
        $api_result = $this->bb->call($svc, $args);

        //Compress the result into a string we can save in the database
        $result_compressed = $this->compress($api_result);

        //If null, convert to string 'NULL' for database, otherwise surround with quores
        $object_type    = is_null($object_type) ? 'NULL' : $object_type;
        $object_id      = is_null($object_id)   ? 'NULL' : "'$object_id'";

        //If we have an id, update it now
        if(!is_null($id)) $this->update($id, $result_compressed, $ttl);

        //No existing record, create one now
        else $this->insert($svc, $object_type, $object_id, $ttl, $result_compressed);

        //Return the direct result from $bb
        return $api_result;
    }
    
    /**
     * Used by call() to update an existing record
     * 
     * @param int $id
     * @param int $ttl
     * @param string $result
     * @return boolean
     */
    private function update($id, $result, $ttl) {
        $statement = $this->db->prepare("
            UPDATE {$this->table}
            SET result = :result, expires = DATE_ADD(UTC_TIMESTAMP(), INTERVAL '$ttl' MINUTE)
            WHERE id = $id
        ");
        return $statement->execute(array(':result' => $result));
    }
    /**
     * Used by call() to create a new cache record
     * 
     * @param string $svc
     * @param int $object_type
     * @param mixed $object_id
     * @param int $ttl
     * @param string $result
     */
    private function insert($svc, $object_type, $object_id, $ttl, $result) {
        $statement = $this->db->prepare("
            INSERT INTO {$this->table}
            (service, object_type, object_id, result, expires)
            VALUES('$svc', $object_type, $object_id, :result, DATE_ADD(UTC_TIMESTAMP(), INTERVAL '$ttl' MINUTE))
        ");
        return $statement->execute(array(':result' => $result));
    }

    /**
     * Compress a value to save into the database
     * Allows us to save large API result sets directly into the database,
     *  without having to worry too much about taking up too much space
     * 
     * @param array     object to compress
     */
    private function compress($result) {
		//First, JSON encode the array / object into a string
		return json_encode($result);
    }
    /**
     * Decompress a value fetched from the database
     */
    private function decompress($text) {
		//json
		return json_decode($text);
    }

    /**
     * Build the WHERE clause for our queries, based on the provided
     *  service name, object type, object id, and any combination of
     * 
     * Note that the 'WHERE' keyword IS returned
     * 
     * @param string $svc
     * @param int $object_type
     * @param mixed $object_id
     * @return string
     */
    private function build_where($svc = null, $object_type = null, $object_id = null) {
        $where = '';
        if(!is_null($svc))          $where .= ($where ? ' AND ' : 'WHERE ') . "`service` = '$svc'";
        if(!is_null($object_type))  $where .= ($where ? ' AND ' : 'WHERE ') . "`object_type` = '$object_type'";
        if(!is_null($object_id))    $where .= ($where ? ' AND ' : 'WHERE ') . "`object_id` = '$object_id'";
        return $where;
    }
}

?>