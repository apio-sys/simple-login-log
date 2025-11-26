<?php
/*
  Plugin Name: Simple Login Log
  Plugin URI: https://apio.systems
  Description: This plugin keeps a log of WordPress user logins. Offers user filtering and export features.
  Version: 2.0.0
  Author: Joris Le Blansch
  Author URI: https://apio.systems
  License: MIT
  License URI: https://github.com/apio-sys/simple-login-log/blob/main/LICENSE
  Text Domain: simple-login-log
  Requires at least: 6.5
  Requires PHP: 8.2
 */

if( !class_exists( 'SimpleLoginLog' ) )
{

 class SimpleLoginLog
 {
    private $db_ver = "1.3";
    public $table = 'simple_login_log';
    private $log_duration = null; //days
    private $opt_name = 'simple_login_log';
    private $opt = false;
    private $login_success = 1;
    public $data_labels = array();
    private $installed_ver = null;
    private $values;

    function __construct()
    {
        global $wpdb;

        if ( is_multisite() )
        {
            // get main site's table prefix
            $main_prefix = $wpdb->get_blog_prefix(1);
            $this->table = $main_prefix . $this->table;
        }
        else
        {
            // non-multisite - regular table name
            $this->table = $wpdb->prefix . $this->table;
        }
        $this->opt = get_option($this->opt_name);
        
        // Ensure $this->opt is always an array
        if ( ! is_array( $this->opt ) ) {
            $this->opt = array();
        }

        //Get plugin's DB version
        $this->installed_ver = get_option( "sll_db_ver" );

        add_action( 'admin_menu', array($this, 'sll_admin_menu') );
        add_action('admin_init', array($this, 'settings_api_init') );
        add_action('admin_head', array($this, 'screen_options') );

        //check if db needs to be upgraded after plugin update was completed
        add_action('plugins_loaded', array($this, 'update_db_check') );

        //Init login actions
        add_action( 'init', array($this, 'init_login_actions') );

        //Init CSV Export
        add_action('admin_init', array($this, 'init_csv_export') );
        add_action('admin_init', array($this, 'delete_all') );

        //Style the log table
        add_action( 'admin_head', array($this, 'admin_header') );

        //Initialize scheduled events (when some one visits site in front-end)
        add_action( 'wp', array($this, 'init_scheduled_events') );
        add_action('truncate_sll', array($this, 'cron') );

        //For translation purposes
        $this->data_labels = array(
            'Successful'        => __('Successful', 'simple-login-log'),
            'Failed'            => __('Failed', 'simple-login-log'),
            'Login'             => __('Login', 'simple-login-log'),
            'User Agent'        => __('User Agent', 'simple-login-log'),
            'Login Redirect'    => __('Login Redirect', 'simple-login-log'),
            'id'                => __('#', 'simple-login-log'),
            'uid'               => __('User ID', 'simple-login-log'),
            'user_login'        => __('Username', 'simple-login-log'),
            'user_role'         => __('User Role', 'simple-login-log'),
            'name'              => __('Name', 'simple-login-log'),
            'time'              => __('Time', 'simple-login-log'),
            'ip'                => __('IP Address', 'simple-login-log'),
            'login_result'      => __('Login Result', 'simple-login-log'),
            'data'              => __('Data', 'simple-login-log'),
        );

        //Deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivation') );

    }


     function set($name, $value)
     {
         $this->values[$name] = $value;
     }


     function get($name)
     {
         return (isset($this->values[$name])) ? $this->values[$name] : false;
     }


    function cron()
    {
        SimpleLoginLog::truncate_log();
    }


    function screen_options()
    {

        //execute only on login_log page, othewise return null
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page parameter for admin screen, read-only
        $page = ( isset($_GET['page']) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : false;
        if( 'login_log' != $page )
            return;

        $current_screen = get_current_screen();

        //define options
        $per_page_field = 'per_page';
        $per_page_option = $current_screen->id . '_' . $per_page_field;

        //Save options that were applied
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress handles nonce verification for screen options internally
        if( isset($_REQUEST['wp_screen_options']) && isset($_REQUEST['wp_screen_options']['value']) )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress handles nonce verification for screen options internally
            update_option( $per_page_option, absint( wp_unslash( $_REQUEST['wp_screen_options']['value'] ) ) );
        }

        //prepare options for display

        //if per page option is not set, use default
        $per_page_val = get_option($per_page_option, 20);
        $args = array('label' => __('Records', 'simple-login-log'), 'default' => $per_page_val );

        //display options
        add_screen_option($per_page_field, $args);
        $_per_page = get_option('users_page_login_log_per_page');

        //needs to be initialized early enough to pre-fill screen options section in the upper (hidden) area.
        $this->log_table = new SLL_List_Table;
    }


    function init_login_actions()
    {
        //condition to check if "log failed attempts" option is selected

        //Action on successful login
        add_action( 'wp_login', array($this, 'login_success') );

        //Action on failed login
        if( isset($this->opt['failed_attempts']) ){
            add_action( 'wp_login_failed', array($this, 'login_failed') );
        }

    }


    function login_success( $user_login )
    {
        $this->login_success = 1;
        $this->login_action( $user_login );
    }


    function login_failed( $user_login )
    {
        $this->login_success = 0;
        $this->login_action( $user_login );
    }


    function init_scheduled_events()
    {

        $log_duration = get_option('simple_login_log');

        if ( $log_duration && !wp_next_scheduled( 'truncate_sll' ) )
        {
            $start = time();
            wp_schedule_event($start, 'daily', 'truncate_sll');
        } elseif( !$log_duration || 0 == $log_duration)
        {
            $timestamp = wp_next_scheduled( 'truncate_sll' );
            (!$timestamp) ? false : wp_unschedule_event($timestamp, 'truncate_sll');

        }
    }


    function deactivation()
    {
        wp_clear_scheduled_hook('truncate_sll');

        //clean up old cron jobs that no longer exist
        wp_clear_scheduled_hook('truncate_log');
        wp_clear_scheduled_hook('SimpleLoginLog::truncate_log');
    }


    function truncate_log()
    {
        global $wpdb;

        $opt = get_option('simple_login_log');
        $log_duration = (int)$opt['log_duration'];

        if( 0 < $log_duration ){
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `{$this->table}` WHERE time < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                    $log_duration
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

    }


     function delete_all()
     {
         global $wpdb;

         $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : false;

         if (!wp_verify_nonce($nonce, 'delete_sll'))
         {
             return;
         }

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
         $result = $wpdb->query(
             $wpdb->prepare( "DELETE FROM `{$this->table}` WHERE 1 = %d", 1 )
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

         if ($result)
         {
             $this->set('deleted', true);
         }
     }



    /**
    * Runs via plugin activation hook & creates a database
    */
    function install()
    {
        global $wpdb;

        if( $this->installed_ver != $this->db_ver )
        {
            //if table does't exist, create a new one
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_exists = $wpdb->get_var(
                $wpdb->prepare( "SHOW TABLES LIKE %s", $this->table )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            
            if( !$table_exists ){
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sql = "CREATE TABLE `{$this->table}`
                    (
                        id INT( 11 ) NOT NULL AUTO_INCREMENT ,
                        uid INT( 11 ) NOT NULL ,
                        user_login VARCHAR( 60 ) NOT NULL ,
                        user_role VARCHAR( 255 ) NOT NULL ,
                        time DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL ,
                        ip VARCHAR( 100 ) NOT NULL ,
                        login_result VARCHAR (1) ,
                        data LONGTEXT NOT NULL ,
                        PRIMARY KEY ( id ) ,
                        INDEX ( uid, ip, login_result )
                    );";
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);

                update_option( "sll_db_ver", $this->db_ver );
            }
        }


    }


    /**
    * Checks if the installed database version is the same as the db version of the current plugin
    * calls the version specific function if upgrade is required
    */
    function update_db_check()
    {
        if ( get_site_option( 'sll_db_ver' ) != $this->db_ver )
        {
            switch( $this->db_ver )
            {
                case "1.1":
                    $this->db_update_1_1();
                    break;
                case "1.2":
                    $this->db_update_1_2();
                    break;
                case "1.3":
                    $this->db_update_1_3();
                    break;
            }
        }
    }


    /**
    * DB version specific updates
    */
    function db_update_1_1()
    {
        /* this version adds a new field "login_result"
         * check if this field exists
         */
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fields = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$this->table}` LIMIT %d", 1 ),
            'ARRAY_A'
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('login_result', $field_names) )
        {
            //add the new field since it doesn't exist
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $insert = $wpdb->query(
                "ALTER TABLE `{$this->table}` ADD COLUMN login_result varchar(1) NOT NULL AFTER ip, ADD INDEX (login_result)"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            //update version record if it has been updated
            if( false !== $insert )
                update_option( "sll_db_ver", $this->db_ver );

        }

    }


    function db_update_1_2()
    {
        /* this version adds a new field "user_role"
         * check if this field exists
         */
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fields = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$this->table}` LIMIT %d", 1 ),
            'ARRAY_A'
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('user_role', $field_names) )
        {
            //add the new field since it doesn't exist
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $insert = $wpdb->query(
                "ALTER TABLE `{$this->table}` ADD COLUMN user_role varchar(30) NOT NULL AFTER user_login"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            //update version record if it has been updated
            if( false !== $insert )
                update_option( "sll_db_ver", $this->db_ver );

        }
    }


     function db_update_1_3()
     {
         /**
          * modifies column data length for user_role
          */
         global $wpdb;

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
         $fields = $wpdb->get_row(
             $wpdb->prepare( "SELECT * FROM `{$this->table}` LIMIT %d", 1 ),
             'ARRAY_A'
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

         if( !$fields ){
             $this->install();
             return;
         }

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
         $insert = $wpdb->query(
             "ALTER TABLE `{$this->table}` MODIFY user_role varchar(255) NOT NULL"
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

         //update version record if it has been updated
         if( false !== $insert )
             update_option( "sll_db_ver", $this->db_ver );

     }


    //Initializing Settings
    function settings_api_init()
    {
        add_settings_section('simple_login_log', __('Simple Login Log', 'simple-login-log'), array($this, 'sll_settings'), 'general');
        add_settings_field('field_log_duration', __('Truncate Log Entries', 'simple-login-log'), array($this, 'field_log_duration'), 'general', 'simple_login_log');
        add_settings_field('field_log_failed_attempts', __('Log Failed Attempts', 'simple-login-log'), array($this, 'field_log_failed_attempts'), 'general', 'simple_login_log');
        register_setting( 'general', 'simple_login_log', array($this, 'sanitize_settings') );

    }


    function sanitize_settings( $input )
    {
        // Get existing settings to preserve values not being updated
        $existing = get_option( 'simple_login_log', array() );
        
        // Start with existing settings
        $sanitized = is_array( $existing ) ? $existing : array();
        
        // Update with new sanitized values
        if ( isset( $input['log_duration'] ) ) {
            $sanitized['log_duration'] = absint( $input['log_duration'] );
        }
        
        // Checkboxes don't send anything when unchecked, so we always set this value
        // If isset, it's checked (true), if not isset, it's unchecked (false)
        $sanitized['failed_attempts'] = isset( $input['failed_attempts'] ) ? (bool) $input['failed_attempts'] : false;
        
        return $sanitized;
    }


    function sll_admin_menu()
    {
        add_submenu_page( 'users.php', __('Simple Login Log', 'simple-login-log'), __('Login Log', 'simple-login-log'), 'list_users', 'login_log', array($this, 'log_manager') );
    }


    function sll_settings()
    {
        //content that goes before the fields output
    }


    function field_log_duration()
    {
        $duration = ( isset($this->opt['log_duration']) ) ? $this->opt['log_duration'] : '';
        ?>
        <input type="text" value="<?php echo esc_attr($duration); ?>" name="simple_login_log[log_duration]" size="10" class="code" /> <?php echo esc_html__('days and older.', 'simple-login-log'); ?>
        <p><?php echo esc_html__("Leave empty or enter 0 if you don't want the log to be truncated.", 'simple-login-log'); ?></p>
        <?php

        //since we're on the General Settings page - update cron schedule if settings has been updated
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress Settings API handles nonce verification
        if( isset($_REQUEST['settings-updated']) ){
            wp_clear_scheduled_hook('truncate_sll');
            //$this->init_scheduled_events();
        }
    }


    function field_log_failed_attempts()
    {
        $failed_attempts = ( isset($this->opt['failed_attempts']) ) ? $this->opt['failed_attempts'] : false;
        echo '<input type="checkbox" name="simple_login_log[failed_attempts]" value="1" ' . checked( $failed_attempts, 1, false ) . ' /> ' . esc_html__('Logs failed attempts where user name and password are entered. Will not log if at least one of the mentioned fields is empty.', 'simple-login-log');
    }


    function admin_header()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page parameter for admin screen, read-only
        $page = ( isset($_GET['page']) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : false;
        if( 'login_log' != $page )
            return;

        echo '<style type="text/css">';
        echo 'table.users { table-layout: auto; }';
        echo '</style>';
    }


    //Catch messages on successful login
    function login_action($user_login)
    {

        $userdata = get_user_by('login', $user_login);

        $uid = ($userdata && $userdata->ID) ? $userdata->ID : 0;

        $data[$this->data_labels['Login']] = ( 1 == $this->login_success ) ? $this->data_labels['Successful'] : $this->data_labels['Failed'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading redirect_to from WordPress login, no nonce needed for logging
        if ( isset( $_REQUEST['redirect_to'] ) ) { $data[$this->data_labels['Login Redirect']] = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ); }
        $data[$this->data_labels['User Agent']] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $serialized_data = serialize($data);

        //get user role
        $user_role = '';
        if( $uid ){
            $user = new WP_User( $uid );
            if ( !empty( $user->roles ) && is_array( $user->roles ) ) {
                $user_role = implode(', ', $user->roles);
            }
        }


        $values = array(
            'uid'           => $uid,
            'user_login'    => $user_login,
            'user_role'     => $user_role,
            'time'          => current_time('mysql'),
            'ip'            => isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ),
            'login_result'  => $this->login_success,
            'data'          => $serialized_data,
            );

        $format = array('%d', '%s', '%s', '%s', '%s', '%s', '%s');

        $this->save_data($values, $format);
    }


    function save_data($values, $format)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT into custom log table, this is the core functionality of the plugin
        $wpdb->insert( $this->table, $values, $format );
    }


    /**
     * Build WHERE clause components for prepared statements.
     * Returns an array with 'clauses' (SQL fragments with placeholders) and 'values' (values to bind).
     *
     * @return array{clauses: string[], values: array}
     */
    function make_where_query()
    {
        $clauses = array();
        $values = array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters from URL for display filtering
        if( isset($_GET['filter']) && '' !== $_GET['filter'] )
        {
            $filter = sanitize_text_field( wp_unslash( $_GET['filter'] ) );
            $clauses[] = "(user_login LIKE %s OR ip LIKE %s)";
            $values[] = '%' . $GLOBALS['wpdb']->esc_like( $filter ) . '%';
            $values[] = '%' . $GLOBALS['wpdb']->esc_like( $filter ) . '%';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters from URL for display filtering
        if( isset($_GET['user_role']) && '' !== $_GET['user_role'] )
        {
            $user_role = sanitize_text_field( wp_unslash( $_GET['user_role'] ) );
            $clauses[] = "user_role LIKE %s";
            $values[] = '%' . $GLOBALS['wpdb']->esc_like( $user_role ) . '%';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters from URL for display filtering
        if( isset($_GET['result']) && '' !== $_GET['result'] )
        {
            $result = sanitize_text_field( wp_unslash( $_GET['result'] ) );
            $clauses[] = "login_result = %s";
            $values[] = $result;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters from URL for display filtering
        if( isset($_GET['datefilter']) && '' !== $_GET['datefilter'] )
        {
            $datefilter = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            // Validate format: YYYYMM
            if ( preg_match( '/^(\d{4})(\d{2})$/', $datefilter, $matches ) ) {
                $clauses[] = "YEAR(time) = %d AND MONTH(time) = %d";
                $values[] = (int) $matches[1];
                $values[] = (int) $matches[2];
            }
        }

        return array(
            'clauses' => $clauses,
            'values'  => $values,
        );
    }


    /**
     * Build WHERE clause components specifically for date filter only (used for stats).
     * Returns an array with 'clauses' (SQL fragments with placeholders) and 'values' (values to bind).
     *
     * @return array{clauses: string[], values: array}
     */
    function make_datefilter_where_query()
    {
        $clauses = array();
        $values = array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters from URL for display filtering
        if( isset($_GET['datefilter']) && '' !== $_GET['datefilter'] )
        {
            $datefilter = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            // Validate format: YYYYMM
            if ( preg_match( '/^(\d{4})(\d{2})$/', $datefilter, $matches ) ) {
                $clauses[] = "YEAR(time) = %d AND MONTH(time) = %d";
                $values[] = (int) $matches[1];
                $values[] = (int) $matches[2];
            }
        }

        return array(
            'clauses' => $clauses,
            'values'  => $values,
        );
    }


    function getLimit()
    {
        return ' LIMIT ' . get_option('users_page_login_log_per_page', 20);
    }


    function log_get_data($orderby = false, $order = false, $limit = 0, $offset = 0)
    {
        global $wpdb;

        // Whitelist allowed columns for ORDER BY
        $orderCol = array(
            'uid' => 'uid',
            'user_login' => 'user_login',
            'time' => 'time',
            'ip' => 'ip'
        );
        
        // Whitelist allowed directions for ORDER BY
        $orderDir = array(
            'asc' => 'ASC',
            'desc'=> 'DESC'
        );

        $orderby = isset($orderCol[$orderby]) ? $orderCol[$orderby] : 'time';
        $order   = isset($orderDir[$order]) ? $orderDir[$order] : 'DESC';

        $where_data = $this->make_where_query();
        $where_sql = '';
        $query_values = array();

        if( !empty($where_data['clauses']) ) {
            $where_sql = ' WHERE ' . implode(' AND ', $where_data['clauses']);
            $query_values = $where_data['values'];
        }

        // Add limit and offset to values
        $query_values[] = $limit;
        $query_values[] = $offset;

        // Build the full query - orderby and order are whitelisted, table is internal
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM `{$this->table}`{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $data = $wpdb->get_results(
            $wpdb->prepare( $sql, $query_values ),
            'ARRAY_A'
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

        return $data;
    }


    function log_manager()
    {

        $log_table = $this->log_table;

        $log_table->prepare_items();

        echo '<div class="wrap srp">';
            echo '<h2>' . esc_html__('Login Log', 'simple-login-log') . '</h2>';

            if ($this->get('deleted'))
            {
                echo '<div class="updated"><p>All records were deleted.</p></div>';
            }

            echo '<div class="tablenav top">';
                echo '<div class="alignleft actions">';
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- date_filter() returns wp_kses sanitized HTML
                    echo $this->date_filter();
                echo '</div>';

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter for display, read-only
                $username = ( isset($_GET['filter']) ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : '';
                echo '<form method="get" class="alignright">';
                    echo '<p class="search-box">';
                        echo '<input type="hidden" name="page" value="login_log" />';
                        echo '<label>' . esc_html__('Username:', 'simple-login-log') . ' </label><input type="text" name="filter" class="filter-username" value="' . esc_attr($username) . '" /> <input class="button" type="submit" value="' . esc_attr__('Filter User', 'simple-login-log') . '" />';
                        echo '<br />';
                    echo '</p>';
                echo '</form>';
            echo '</div>';
            echo '<div class="tablenav top">';

                //if log failed attempts is set in the settings, then output views filter
                if( isset($this->opt['failed_attempts']) ){
                    echo '<div class="alignleft actions">';
                            $log_table->views();
                    echo '</div>';
                }

                echo '<div class="alignright actions">';
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading mode parameter for display, read-only
                $mode = ( isset($_GET['mode']) ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';
                $log_table->view_switcher($mode);
                echo '</div>';
            echo '</div>';

            $log_table->display();

            echo '<form method="get" id="export-login-log">';
            if ( function_exists('wp_nonce_field') )
                wp_nonce_field('ssl_export_log');

            echo '<input type="hidden" name="page" value="login_log" />';
            echo '<input type="hidden" name="download-login-log" value="true" />';
            echo '<p class="submit">';
            echo '<input type="submit" name="submit" id="submit" class="button" value="Export Log to CSV">';
            echo '&nbsp;&nbsp;<a id="delete-all" href="' . esc_url(wp_nonce_url('users.php?page=login_log&action=delete', 'delete_sll')) . '" onclick="return confirm(\'IMPORTANT: All User Log records will be deleted.\')">Delete All</a>';
            echo '</p>';
            echo '</form>';
            //if filtered results - add export filtered results button
            $where = false;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking if filters are set for export, nonce verified during export
            if( isset( $_GET['filter'] ) || isset( $_GET['user_role'] ) || isset( $_GET['datefilter'] ) || isset( $_GET['result'] ) )
            {
                $where = array();
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building filter array for export, nonce verified during export
                foreach($_GET as $k => $v)
                {
                    $where[ sanitize_key( $k ) ] = sanitize_text_field( $v );
                }
                echo '<form method="get" id="export-login-log">';
                if ( function_exists('wp_nonce_field') )
                    wp_nonce_field('ssl_export_log');

                echo '<input type="hidden" name="page" value="login_log" />';
                echo '<input type="hidden" name="download-login-log" value="true" />';
                echo '<input type="hidden" name="where" value="' . esc_attr( wp_json_encode( $where ) ) . '" />';
                submit_button( __('Export Current Results to CSV', 'simple-login-log'), 'secondary' );
                echo '</form>';

            }

        echo '</div>';
    }


    function date_filter()
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT YEAR(time) as year, MONTH(time) as month FROM `{$this->table}` WHERE %d = %d ORDER BY YEAR(time), MONTH(time) DESC",
                1, 1
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if(!$results)
            return '';

        $option = '';
        foreach($results as $row)
        {
            //represent month in double digits
            $timestamp = mktime(0, 0, 0, $row->month, 1, $row->year);
            $month = (strlen($row->month) == 1) ? '0' . $row->month : $row->month;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter for display, read-only
            $datefilter = ( isset($_GET['datefilter']) ) ? sanitize_text_field( wp_unslash( $_GET['datefilter'] ) ) : '';
            $option .= '<option value="' . esc_attr($row->year . $month) . '" ' . selected($row->year . $month, $datefilter, false) . '>' . esc_html(date_i18n('F', $timestamp)) . ' ' . esc_html($row->year) . '</option>';
        }

        $output = '<form method="get">';
        $output .= '<input type="hidden" name="page" value="login_log" />';
        $output .= '<select name="datefilter"><option value="">' . esc_html__('View All', 'simple-login-log') . '</option>' . $option . '</select>';
        $output .= '<input class="button" type="submit" value="' . esc_attr__('Filter', 'simple-login-log') . '" />';
        $output .= '</form>';
        
        // Allow form elements for the date filter
        $allowed_html = array(
            'form' => array('method' => array()),
            'input' => array(
                'type' => array(),
                'name' => array(),
                'value' => array(),
                'class' => array(),
            ),
            'select' => array('name' => array()),
            'option' => array(
                'value' => array(),
                'selected' => array(),
            ),
        );
        
        return wp_kses($output, $allowed_html);
    }


    function init_csv_export()
    {
        //Check if download was initiated

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below with check_admin_referer()
        $download = (isset($_GET['download-login-log'])) ? sanitize_text_field( wp_unslash( $_GET['download-login-log'] ) ) : false;

        if($download)
        {
            check_admin_referer( 'ssl_export_log' );

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON data will be decoded and sanitized
            $where_json = ( isset($_GET['where']) && '' !== $_GET['where'] ) ? wp_unslash( $_GET['where'] ) : false;
            
            if ( $where_json ) {
                $where = json_decode( $where_json, true );
                if ( is_array( $where ) ) {
                    foreach( $where as $k => $v ) {
                        $_GET[ sanitize_key( $k ) ] = sanitize_text_field( $v );
                    }
                }
            }

            $this->export_to_CSV( $this->make_where_query() );
        }
    }


    function export_to_CSV( $where_data = false ){
        global $wpdb;

        $where_sql = '';
        $query_values = array();

        //if $where_data is set, build WHERE sql query with prepared statement
        if( $where_data && !empty($where_data['clauses']) ) {
            $where_sql = ' WHERE ' . implode(' AND ', $where_data['clauses']);
            $query_values = $where_data['values'];
        }

        // Build query
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM `{$this->table}`{$where_sql}";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        if ( !empty($query_values) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $data = $wpdb->get_results(
                $wpdb->prepare( $sql, $query_values ),
                'ARRAY_A'
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $data = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE %d = %d", 1, 1 ),
                'ARRAY_A'
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        if(!$data)
            return;

        //date string to suffix the file nanme: month - day - year - hour - minute
        $suffix = wp_date('n-j-y_H-i');

        // send response headers to the browser
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment;filename=login_log_' . $suffix . '.csv');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://output stream for direct CSV download, not writing to filesystem
        $fp = fopen('php://output', 'w');

        $i = 0;
        foreach($data as $row){
            $tmp = unserialize($row['data']);
            //output header row
            if(0 == $i)
            {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Using php://output stream for direct CSV download
                fputcsv( $fp, array_keys($row) );
            }
            $row_data = (!empty($tmp)) ? array_map(function($key, $value) {
                return $key.": ".$value." | ";
            }, array_keys($tmp), array_values($tmp)) : array();
            $row['data'] = implode($row_data);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Using php://output stream for direct CSV download
            fputcsv($fp, $row);
            $i++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output stream for direct CSV download
        fclose($fp);
        die();
    }

 }

}

if( class_exists( 'SimpleLoginLog' ) )
{
    $sll = new SimpleLoginLog;
    //Register for activation
    register_activation_hook( __FILE__, array(&$sll, 'install') );

}

if(!class_exists('WP_List_Table'))
{
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class SLL_List_Table extends WP_List_Table
{
    private $sllData;
    protected $data_labels;

    function __construct()
    {
        global $sll, $_wp_column_headers;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'user',     //singular name of the listed records
            'plural'    => 'users',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );

        $this->data_labels = $sll->data_labels;
    }


    function set($name, $value)
    {
        $this->sllData[$name] = $value;
    }


    function get($name)
    {
        return (isset($this->sllData[$name])) ? $this->sllData[$name] : false;
    }


    function column_default($item, $column_name)
    {
        $item = apply_filters('sll-output-data', $item);

        //unset existing filter and pagination
        $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $args = wp_parse_args( wp_parse_url( $current_url, PHP_URL_QUERY ) );
        unset($args['filter']);
        unset($args['paged']);

        switch($column_name){
            case 'id':
            case 'uid':
            case 'time':
            case 'ip':
                return esc_html($item[$column_name]);
            case 'user_login':
                $url = esc_url(add_query_arg( array('filter' => $item[$column_name]), menu_page_url('login_log', false) ));
                $allowed_html = array(
                    'a' => array(
                        'href' => array(),
                        'title' => array(),
                    ),
                );
                return wp_kses("<a href='" . $url . "' title='" . esc_attr__('Filter log by this name', 'simple-login-log') . "'>" . esc_html($item[$column_name]) . "</a>", $allowed_html);
            case 'name':
                $user_info = get_userdata($item['uid']);
                return ( is_object($user_info) ) ? esc_html($user_info->first_name) .  " " . esc_html($user_info->last_name) : '';
            case 'login_result':
                if ( '' == $item[$column_name]) return '';
                $allowed_html = array('div' => array('class' => array()));
                return ( '1' == $item[$column_name] ) ? esc_html($this->data_labels['Successful']) : wp_kses('<div class="login-failed">' . esc_html($this->data_labels['Failed']) . '</div>', $allowed_html);
            case 'user_role':
                if( !$item['uid'] )
                    return;

                global $wp_roles;

                $user = new WP_User( $item['uid'] );
                if ( !empty( $user->roles ) && is_array( $user->roles ) )
                {
                    $roles = array();
                    foreach($user->roles as $role)
                    {
                        $roleName = isset($wp_roles->roles[$role]['name']) ? $wp_roles->roles[$role]['name'] : $role;
                        $url = esc_url(add_query_arg( array('user_role' => $role), menu_page_url('login_log', false) ));
                        $roles[] = "<a href='" . $url . "' title='" . esc_attr__('Filter log by User Role', 'simple-login-log') . "'>" . esc_html($roleName) . "</a>";
                    }
                    // Allow anchor tags with href and title attributes
                    $allowed_html = array(
                        'a' => array(
                            'href' => array(),
                            'title' => array(),
                        ),
                    );
                    return wp_kses(implode(', ', $roles), $allowed_html);
                }
                break;
            case 'data':
                $data = unserialize($item[$column_name]);
                if(is_array($data))
                {
                    $output = '';
                    foreach($data as $k => $v)
                    {
                        $output .= esc_html($k) .': '. esc_html($v) .'<br />';
                    }

                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading mode parameter for display, read-only
                    $mode_value = isset($_GET['mode']) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';
                    $output = ( 'excerpt' === $mode_value ) ? $output : substr($output, 0, 50) . '...';

                    // Allow only br and div tags with specific class for the output
                    $allowed_html = array(
                        'br' => array(),
                        'div' => array('class' => array()),
                    );

                    if( isset($data[$this->data_labels['Login']]) && $data[$this->data_labels['Login']] == $this->data_labels['Failed'] ){
                        return wp_kses('<div class="login-failed">' . $output . '</div>', $allowed_html);
                    }
                    return wp_kses($output, $allowed_html);
                }
                break;
            default:
                return esc_html($item[$column_name]);
        }
    }


    function get_columns()
    {
        global $status;
        $columns = array(
            'id'            => __('#', 'simple-login-log'),
            'uid'           => __('User ID', 'simple-login-log'),
            'user_login'    => __('Username', 'simple-login-log'),
            'user_role'     => __('User Role', 'simple-login-log'),
            'name'          => __('Name', 'simple-login-log'),
            'time'          => __('Time', 'simple-login-log'),
            'ip'            => __('IP Address', 'simple-login-log'),
            'login_result'  => __('Login Result', 'simple-login-log'),
            'data'          => __('Data', 'simple-login-log'),
        );
        return $columns;
    }


    function get_sortable_columns()
    {
        $sortable_columns = array(
            //'id'    => array('id',true),     //doesn't sort correctly
            'uid'           => array('uid',false),
            'user_login'    => array('user_login', false),
            'time'          => array('time',true),
            'ip'            => array('ip', false),
        );
        return $sortable_columns;
    }


    // Read-only function that checks URL parameters for display state
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    function get_views()
    {
        //creating class="current" variables
        if( !isset($_GET['result']) ){
            $all = 'class="current"';
            $success = '';
            $failed = '';
        }else{
            $all = '';
            $result_value = isset($_GET['result']) ? sanitize_text_field( wp_unslash( $_GET['result'] ) ) : '';
            $success = ( '1' === $result_value ) ? 'class="current"' : '';
            $failed = ( '0' === $result_value ) ? 'class="current"' : '';
        }



        //if date filter is set, adjust views label to reflect the date
        $date_label = '';
        if( isset($_GET['datefilter']) && !empty($_GET['datefilter']) ){
            $datefilter_value = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            $year = substr($datefilter_value, 0, 4);
            $month = substr($datefilter_value, -2);
            $timestamp = mktime(0, 0, 0, $month, 1, $year);
            $date_label = esc_html(date_i18n('F', $timestamp)) . ' ' . esc_html($year) . ' ';
        }

        //get args from the URL
        $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $args = wp_parse_args( wp_parse_url( $current_url, PHP_URL_QUERY ) );
        //the only arguments we can pass are mode and datefilter
        $param = false;
        if( isset($args['mode']) )
            $param['mode'] = $args['mode'];

        if( isset($args['datefilter']) )
            $param['datefilter'] = $args['datefilter'];

        //creating base url for the views links
        $menu_page_url = menu_page_url('login_log', false);
        ( is_array($param) && !empty($param) ) ? $url = add_query_arg( $param, $menu_page_url) : $url = $menu_page_url;

        //definition for views array
        $views = array(
            'all' => $date_label . esc_html__('Login Results', 'simple-login-log') . ': <a ' . $all . ' href="' . esc_url($url) . '">' . esc_html__('All', 'simple-login-log') . '</a>' . '(' . absint($this->get('allTotal')) . ')',
            'success' => '<a ' . $success . ' href="' . esc_url($url) . '&result=1">' . esc_html__('Successful', 'simple-login-log') . '</a> (' . absint($this->get('successTotal')) . ')',
            'failed' => '<a ' . $failed . ' href="' . esc_url($url) . '&result=0">' . esc_html__('Failed', 'simple-login-log') . '</a>' . '(' . absint($this->get('failedTotal')) . ')',
        );

        return $views;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended


    function prepare_items()
    {
        global $wpdb, $sll;

        // Get date filter for stats (only use datefilter, not other filters)
        $date_where = $sll->make_datefilter_where_query();
        
        // Build base WHERE clause for stats
        $base_where_sql = '';
        $base_values = array();
        if ( !empty($date_where['clauses']) ) {
            $base_where_sql = implode(' AND ', $date_where['clauses']);
            $base_values = $date_where['values'];
        }

        // Count all records (with optional date filter)
        if ( $base_where_sql ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $allTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$sll->table}` WHERE {$base_where_sql}",
                    $base_values
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $allTotal = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$sll->table}` WHERE %d = %d", 1, 1 )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Count successful logins
        $success_values = $base_values;
        $success_values[] = '1';
        if ( $base_where_sql ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $successTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$sll->table}` WHERE {$base_where_sql} AND login_result = %s",
                    $success_values
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $successTotal = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$sll->table}` WHERE login_result = %s", '1' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Count failed logins
        $failed_values = $base_values;
        $failed_values[] = '0';
        if ( $base_where_sql ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $failedTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$sll->table}` WHERE {$base_where_sql} AND login_result = %s",
                    $failed_values
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $failedTotal = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$sll->table}` WHERE login_result = %s", '0' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $this->set('allTotal', $allTotal);
        $this->set('successTotal', $successTotal);
        $this->set('failedTotal', $failedTotal);

        $screen = get_current_screen();

        /**
         * First, lets decide how many records per page to show
         */
        $per_page_option = $screen->id . '_per_page';
        $per_page = get_option($per_page_option, 20);
        $per_page = ($per_page != false) ? $per_page : 20;

        $offset = $per_page * ($this->get_pagenum() - 1);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading orderby parameter for sorting, read-only, validated against whitelist
        $orderby = (isset($_REQUEST['orderby']) && !empty($_REQUEST['orderby'])) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading order parameter for sorting, read-only, validated against whitelist
        $order = (isset($_REQUEST['order']) && !empty($_REQUEST['order'])) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : false;

        $this->items = $sll->log_get_data($orderby, $order, $per_page, $offset);

        /**
         * REQUIRED. Now we need to define our column headers. This includes a complete
         * array of columns to be displayed (slugs & titles), a list of columns
         * to keep hidden, and a list of columns that are sortable. Each of these
         * can be defined in another method (as we've done here) before being
         * used to build the value for our _column_headers property.
         */
        $columns = $this->get_columns();
        $hidden_cols = get_user_option( 'manage' . $screen->id . 'columnshidden' );
        $hidden = ( $hidden_cols ) ? $hidden_cols : array();
        $sortable = $this->get_sortable_columns();


        /**
         * REQUIRED. Finally, we build an array to be used by the class for column
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        $columns = get_column_headers( $screen );


        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently
         * looking at. We'll need this later, so you should always include it in
         * your own package classes.
         */
        $current_page = $this->get_pagenum();

        /**
         * REQUIRED for pagination. Let's check how many items are in our data array.
         * Get total items based on current filters
         */
        $where_data = $sll->make_where_query();
        
        if ( !empty($where_data['clauses']) ) {
            $where_sql = implode(' AND ', $where_data['clauses']);
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $total_items = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$sll->table}` WHERE {$where_sql}",
                    $where_data['values']
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total_items = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$sll->table}` WHERE %d = %d", 1, 1 )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }


        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );

    }

}

