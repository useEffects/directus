<?php

namespace Ruckusing;

use Ruckusing\RuckusingException as Ruckusing_Exception;
use Ruckusing\FrameworkInterface;
use Ruckusing\Util\Logger as Ruckusing_Util_Logger;
use Ruckusing\Task\TaskManager as Ruckusing_Task_Manager;

abstract class FrameworkAbstract implements FrameworkInterface
{
    /**
     * reference to our DB connection
     *
     * @var array
     */
    private $_db = null;

    /**
     * The currently active config
     *
     * @var array
     */
    private $_active_db_config;

    /**
     * Available DB config (e.g. test,development, production)
     *
     * @var array
     */
    private $_config = array();

    /**
     * Task manager
     *
     * @var Ruckusing_Task_Manager
     */
    protected $_task_mgr = null;

    /**
     * adapter
     *
     * @var Ruckusing_Adapter_Base
     */
    private $_adapter = null;

    /**
     * current task name
     *
     * @var string
     */
    protected $_cur_task_name = "";

    /**
     * task options
     *
     * @var string
     */
    protected $_task_options = "";

    /**
     * Environment
     * default is development
     * but can also be one 'test', 'production', etc...
     *
     * @var string
     */
    private $_env = "development";

    /**
     * set up some defaults
     *
     * @var array
     */
    private $_opt_map = array(
            'env' => 'development'
    );

    /**
     * Flag to display help of task
     * @see Ruckusing_FrameworkRunner::parse_args
     *
     * @var boolean
     */
    protected $_showhelp = false;

    /**
     * Creates an instance of Ruckusing_Adapters_Base
     *
     * @param array $config The current config
     * @param array $argv   the supplied command line arguments
     * @param Ruckusing_Util_Logger An optional custom logger
     *
     * @return Ruckusing_FrameworkRunner
     */
    public function __construct($config, $argv = array(), Ruckusing_Util_Logger $log = null)
    {
        set_error_handler(array('Ruckusing\RuckusingException', 'errorHandler'), E_ALL);
        set_exception_handler(array('Ruckusing\RuckusingException', 'exceptionHandler'));

        //parse arguments
        $this->parse_args($argv);

        //set config variables
        $this->_config = $config;

        //verify config array
        $this->verify_db_config();

        //initialize logger
        $this->logger = $log;
        // @todo: logger should be working here
        try {
            $this->initialize_logger();
        } catch(Exception $e) {};

        //include all adapters
        //$this->load_all_adapters(RUCKUSING_BASE . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Ruckusing' . DIRECTORY_SEPARATOR . 'Adapter');

        //initialize logger
        $this->initialize_db();

        //initialize tasks
        $this->init_tasks();

    }

    /**
     * Get the current adapter
     *
     * @return object
     */
    public function get_adapter()
    {
        return $this->_adapter;
    }

    /**
     * Initialize the task manager
     */
    public function init_tasks()
    {
        $this->_task_mgr = new Ruckusing_Task_Manager($this->_adapter, $this->_config);
    }

    /**
     * Get the current migration dir
     *
     * @param string $key the module key name
     *
     * @return string
     */
    public function migrations_directory($key = '')
    {
        $migration_dir = '';

        if ($key) {
            if (!isset($this->_config['migrations_dir'][$key])) {
                throw new Ruckusing_Exception(
                                sprintf("No module %s migration_dir set in config", $key),
                                Ruckusing_Exception::INVALID_CONFIG
                );
            }
            $migration_dir = $this->_config['migrations_dir'][$key] . DIRECTORY_SEPARATOR;
        } elseif (is_array($this->_config['migrations_dir'])) {
            $migration_dir = $this->_config['migrations_dir']['default'] . DIRECTORY_SEPARATOR;
        } else {
            $migration_dir = $this->_config['migrations_dir'] . DIRECTORY_SEPARATOR;
        }

        if (array_key_exists('directory', $this->_config['db'][$this->_env])) {
            return $migration_dir . $this->_config['db'][$this->_env]['directory'];
        }

        return $migration_dir . $this->_config['db'][$this->_env]['database'];
    }

    /**
     * Get all migrations directory
     *
     * @return array
     */
    public function migrations_directories()
    {
        $folder = $this->_config['db'][$this->_env]['database'];
        if (array_key_exists('directory', $this->_config['db'][$this->_env])) {
            $folder = $this->_config['db'][$this->_env]['directory'];
        }

        $result = array();
        if (is_array($this->_config['migrations_dir'])) {
            foreach ($this->_config['migrations_dir'] as $name => $path) {
                $result[$name] = $path . DIRECTORY_SEPARATOR . $folder;
            }
        } else {
            $result['default'] = $this->_config['migrations_dir'] . DIRECTORY_SEPARATOR . $folder;
        }

        return $result;
    }

    /**
     * Get the current db schema dir
     *
     * @return string
     */
    public function db_directory()
    {
        $path = $this->_config['db_dir'] . DIRECTORY_SEPARATOR;

        if (array_key_exists('directory', $this->_config['db'][$this->_env])) {
            return $path . $this->_config['db'][$this->_env]['directory'];
        }

        return $path . $this->_config['db'][$this->_env]['database'];
    }

    /**
     * Initialize the db
     */
    public function initialize_db()
    {
        $db = $this->_config['db'][$this->_env];
        $adapter = $this->get_adapter_class($db['type']);

        if (empty($adapter)) {
            throw new Ruckusing_Exception(
                            sprintf("No adapter available for DB type: %s", $db['type']),
                            Ruckusing_Exception::INVALID_ADAPTER
            );
        }
        //construct our adapter
        $this->_adapter = new $adapter($db, $this->logger, $this->_config);

    }

    /**
     * Initialize the logger
     */
    public function initialize_logger()
    {
        if (!$this->logger) {
            if (is_dir($this->_config['log_dir']) && !is_writable($this->_config['log_dir'])) {
                throw new Ruckusing_Exception(
                                "\n\nCannot write to log directory: " . $this->_config['log_dir'] . "\n\nCheck permissions.\n\n",
                                Ruckusing_Exception::INVALID_LOG
                );
            } elseif (!is_dir($this->_config['log_dir'])) {
                //try and create the log directory
                mkdir($this->_config['log_dir'], 0755, true);
            }
            $log_name = sprintf("%s.log", $this->_env);
            $this->logger = Ruckusing_Util_Logger::instance($this->_config['log_dir'] . DIRECTORY_SEPARATOR . $log_name);
        }
    }

    /**
     * Update the local schema to handle multiple records versus the prior architecture
     * of storing a single version. In addition take all existing migration files
     * and register them in our new table, as they have already been executed.
     */
    public function update_schema_for_timestamps()
    {
        //only create the table if it doesnt already exist
        $this->_adapter->create_schema_version_table();
        //insert all existing records into our new table
        $migrator_util = new Ruckusing_Util_Migrator($this->_adapter);
        $files = $migrator_util->get_migration_files($this->migrations_directories(), 'up');
        $table_name = RUCKUSING_SCHEMA_TBL_NAME;
        $table_ts_name = RUCKUSING_TS_SCHEMA_TBL_NAME;
        if(isset($this->_config['prefix'])) {
            $table_name = RUCKUSING_SCHEMA_TBL_NAME . $table_name;
            $table_ts_name = RUCKUSING_TS_SCHEMA_TBL_NAME . $table_ts_name;
        }
        foreach ($files as $file) {
            if ((int) $file['version'] >= PHP_INT_MAX) {
                //its new style like '20081010170207' so its not a candidate
                continue;
            }
            //query old table, if it less than or equal to our max version, then its a candidate for insertion
            $query_sql = sprintf("SELECT version FROM %s WHERE version >= %d", $table_name, $file['version']);
            $existing_version_old_style = $this->_adapter->select_one($query_sql);
            if (count($existing_version_old_style) > 0) {
                //make sure it doesnt exist in our new table, who knows how it got inserted?
                $new_vers_sql = sprintf("SELECT version FROM %s WHERE version = %d", $table_ts_name, $file['version']);
                $existing_version_new_style = $this->_adapter->select_one($new_vers_sql);
                if (empty($existing_version_new_style)) {
                    // use sprintf & %d to force it to be stripped of any leading zeros, we *know* this represents an old version style
                    // so we dont have to worry about PHP and integer overflow
                    $insert_sql = sprintf("INSERT INTO %s (version) VALUES (%d)", $table_ts_name, $file['version']);
                    $this->_adapter->query($insert_sql);
                }
            }
        }
    }

    /**
     * Set an option
     *
     * @param string $key   the key to set
     * @param string $value the value to set
     */
    private function set_opt($key, $value)
    {
        if (!$key) {
            return;
        }
        $this->_opt_map[$key] = $value;
    }

    /**
     * Verify db config
     */
    private function verify_db_config()
    {
        if ( !array_key_exists($this->_env, $this->_config['db'])) {
            throw new Ruckusing_Exception(
                            sprintf("Error: '%s' DB is not configured", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        $env = $this->_env;
        $this->_active_db_config = $this->_config['db'][$this->_env];
        if (!array_key_exists("type",$this->_active_db_config)) {
            throw new Ruckusing_Exception(
                            sprintf("Error: 'type' is not set for '%s' DB", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (!array_key_exists("host",$this->_active_db_config)) {
            throw new Ruckusing_Exception(
                            sprintf("Error: 'host' is not set for '%s' DB", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (!array_key_exists("database",$this->_active_db_config)) {
            throw new Ruckusing_Exception(
                            sprintf("Error: 'database' is not set for '%s' DB", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (!array_key_exists("user",$this->_active_db_config)) {
            throw new Ruckusing_Exception(
                            sprintf("Error: 'user' is not set for '%s' DB", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (!array_key_exists("password",$this->_active_db_config)) {
            throw new Ruckusing_Exception(
                            sprintf("Error: 'password' is not set for '%s' DB", $this->_env),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (empty($this->_config['migrations_dir'])) {
            throw new Ruckusing_Exception(
                            "Error: 'migrations_dir' is not set in config.",
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (is_array($this->_config['migrations_dir'])) {
            if (!isset($this->_config['migrations_dir']['default'])) {
                throw new Ruckusing_Exception(
                                "Error: 'migrations_dir' 'default' key is not set in config.",
                                Ruckusing_Exception::INVALID_CONFIG
                );
            } elseif (empty($this->_config['migrations_dir']['default'])) {
                throw new Ruckusing_Exception(
                                "Error: 'migrations_dir' 'default' key is empty in config.",
                                Ruckusing_Exception::INVALID_CONFIG
                );
            } else {
                $names = $paths = array();
                foreach ($this->_config['migrations_dir'] as $name => $path) {
                    if (isset($names[$name])) {
                        throw new Ruckusing_Exception(
                                        "Error: 'migrations_dir' '$name' key is defined multiples times in config.",
                                        Ruckusing_Exception::INVALID_CONFIG
                        );
                    }
                    if (isset($paths[$path])) {
                        throw new Ruckusing_Exception(
                                        "Error: 'migrations_dir' '{$paths[$path]}' and '$name' keys defined the same path in config.",
                                        Ruckusing_Exception::INVALID_CONFIG
                        );
                    }
                    $names[$name] = $path;
                    $paths[$path] = $name;
                }
            }
        }
        if (isset($this->_task_options['module']) && !isset($this->_config['migrations_dir'][$this->_task_options['module']])) {
            throw new Ruckusing_Exception(
                            sprintf("Error: module name %s is not set in 'migrations_dir' option in config.", $this->_task_options['module']),
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (empty($this->_config['db_dir'])) {
            throw new Ruckusing_Exception(
                            "Error: 'db_dir' is not set in config.",
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
        if (empty($this->_config['log_dir'])) {
            throw new Ruckusing_Exception(
                            "Error: 'log_dir' is not set in config.",
                            Ruckusing_Exception::INVALID_CONFIG
            );
        }
    }

    /**
     * Get the adapter class
     *
     * @param string $db_type the database type
     *
     * @return string
     */
    private function get_adapter_class($db_type)
    {
        $adapter_class = null;
        switch ($db_type) {
            case 'mysql':
                $adapter_class = "Ruckusing\Adapter\MySQL\MySQLBase";;
                break;
            case 'pgsql':
                $adapter_class = "Ruckusing\Adapter\PgSQLBase";
                break;
            case 'sqlite':
                $adapter_class = "Ruckusing\Adapter\Sqlite3Base";
                break;
        }

        return $adapter_class;
    }

    /**
     * DB adapters are classes in lib/Ruckusing/Adapter
     * and they follow the file name syntax of "<DB Name>/Base.php".
     *
     * See the function "get_adapter_class" in this class for examples.
     *
     * @param string $adapter_dir the adapter dir
     */
    private function load_all_adapters($adapter_dir)
    {
        if (!is_dir($adapter_dir)) {
            throw new Ruckusing_Exception(
                            sprintf("Adapter dir: %s does not exist", $adapter_dir),
                            Ruckusing_Exception::INVALID_ADAPTER
            );

            return false;
        }
        $files = scandir($adapter_dir);
        foreach ($files as $f) {
            //skip over invalid files
            if ($f == '.' || $f == ".." || !is_dir($adapter_dir . DIRECTORY_SEPARATOR . $f)) {
                continue;
            }
            $adapter_class_path = $adapter_dir . DIRECTORY_SEPARATOR . $f . DIRECTORY_SEPARATOR . 'Base.php';
            if(file_exists($adapter_class_path)) {
              require_once $adapter_class_path;
            }
        }
    }

    /**
     * $argv is our complete command line argument set.
     * PHP gives us:
     * [0] = the actual file name we're executing
     * [1..N] = all other arguments
     *
     * Our task name should be at slot [1]
     * Anything else are additional parameters that we can pass
     * to our task and they can deal with them as they see fit.
     *
     * @param array $argv the current command line arguments
     */
    protected function parse_args($argv)
    {
        $num_args = count($argv);

        $options = array();
        for ($i = 0; $i < $num_args; $i++) {
            $arg = $argv[$i];
            if (stripos($arg, ':') !== false) {
                $this->_cur_task_name = $arg;
            } elseif ($arg == 'help') {
                $this->_showhelp = true;
                continue;
            } elseif (stripos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg);
                $key = strtolower($key); // Allow both upper and lower case parameters
                $options[$key] = $value;

                if ($key == 'env') {
                    $this->_env = $value;
                }
            } elseif (stripos($arg, '_') !== false) {
                $options['name'] = $arg;
            }
        }
        $this->_task_options = $options;
    }
}
