<?php
/*
  Plugin Name: Simple Login Log
  Plugin URI: https://apio.systems
  Description: This plugin keeps a log of WordPress user logins. Offers user filtering and export features.
  Version: 2.0.0
  Author: Max Chirkov
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
    private $installed_ver = null; // FIX: Declared property to avoid PHP 8.2+ deprecation warning
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
            $sql = $wpdb->prepare( "DELETE FROM {$this->table} WHERE time < DATE_SUB(CURDATE(),INTERVAL %d DAY)", $log_duration);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared above with $wpdb->prepare()
            $wpdb->query($sql);
        }

    }


     function delete_all()
     {
         global $wpdb;

         // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce is being verified immediately below, wp_verify_nonce() handles sanitization
         $nonce = isset($_REQUEST['_wpnonce']) ? wp_unslash( $_REQUEST['_wpnonce'] ) : false;

         if (!wp_verify_nonce($nonce, 'delete_sll'))
         {
             return;
         }
         else
         {
             // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
             $sql = "DELETE FROM {$this->table}";

             // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input, table name is internal property
             if ($wpdb->query($sql))
             {
                 $this->set('deleted', true);
             }
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES check for custom table, caching not applicable
            if( !$wpdb->get_row("SHOW TABLES LIKE '{$this->table}'") ){
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin installation/upgrade requires creating custom log table
                $sql = "CREATE TABLE  " . $this->table . "
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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
        $sql = "SELECT * FROM {$this->table} LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for custom table, caching not applicable
        $fields = $wpdb->get_row($sql, 'ARRAY_A');

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('login_result', $field_names) )
        {
            //add the new field since it doesn't exist
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
            $sql = "ALTER TABLE {$this->table} ADD COLUMN login_result varchar(1) NOT NULL AFTER ip, ADD INDEX (login_result);";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL operation on custom table, caching not applicable
            $insert = $wpdb->query( $sql );

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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
        $sql = "SELECT * FROM {$this->table} LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for custom table, caching not applicable
        $fields = $wpdb->get_row($sql, 'ARRAY_A');

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('user_role', $field_names) )
        {
            //add the new field since it doesn't exist
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
            $sql = "ALTER TABLE {$this->table} ADD COLUMN user_role varchar(30) NOT NULL AFTER user_login;";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL operation on custom table, caching not applicable
            $insert = $wpdb->query( $sql );

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

         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
         $sql = "SELECT * FROM {$this->table} LIMIT 1";
         // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for custom table, caching not applicable
         $fields = $wpdb->get_row($sql, 'ARRAY_A');

         if( !$fields ){
             $this->install();
             return;
         }

         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
         $sql = "ALTER TABLE {$this->table} MODIFY user_role varchar(255) NOT NULL;";
         // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL operation on custom table, caching not applicable
         $insert = $wpdb->query( $sql );

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
        // Sanitize the settings array
        $sanitized = array();
        
        if ( isset( $input['log_duration'] ) ) {
            $sanitized['log_duration'] = absint( $input['log_duration'] );
        }
        
        if ( isset( $input['failed_attempts'] ) ) {
            $sanitized['failed_attempts'] = (bool) $input['failed_attempts'];
        }
        
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
        $duration = (null !== $this->opt['log_duration']) ? $this->opt['log_duration'] : $this->log_duration;
        $output = '<input type="text" value="' . esc_attr($duration) . '" name="simple_login_log[log_duration]" size="10" class="code" /> ' . esc_html__('days and older.', 'simple-login-log');
        echo wp_kses_post($output);
        echo "<p>" . esc_html__("Leave empty or enter 0 if you don't want the log to be truncated.", 'simple-login-log') . "</p>";

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


    function make_where_query()
    {
        $where = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameters for display, read-only
        if( isset($_GET['filter']) && '' != $_GET['filter'] )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter, already checked with isset above
            $filter = esc_sql( sanitize_text_field( wp_unslash( $_GET['filter'] ) ) );
            $where['filter'] = "(user_login LIKE '%{$filter}%' OR ip LIKE '%{$filter}%')";
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameters for display, read-only
        if( isset($_GET['user_role']) && '' != $_GET['user_role'] )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter, already checked with isset above
            $user_role = esc_sql( sanitize_text_field( wp_unslash( $_GET['user_role'] ) ) );
            $where['user_role'] = "user_role LIKE '%{$user_role}%'";
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameters for display, read-only
        if( isset($_GET['result']) && '' != $_GET['result'] )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter, already checked with isset above
            $result = esc_sql( sanitize_text_field( wp_unslash( $_GET['result'] ) ) );
            $where['result'] = "login_result = '{$result}'";
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameters for display, read-only
        if( isset($_GET['datefilter']) && '' != $_GET['datefilter'] )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter, already checked with isset above
            $datefilter = esc_sql( sanitize_text_field( wp_unslash( $_GET['datefilter'] ) ) );
            $year = substr($datefilter, 0, 4);
            $month = substr($datefilter, -2);
            $where['datefilter'] = "YEAR(time) = {$year} AND MONTH(time) = {$month}";
        }

        return $where;
    }


    function getLimit()
    {
        return ' LIMIT ' . get_option('users_page_login_log_per_page', 20);
    }


    function log_get_data($orderby = false, $order = false, $limit = 0, $offset = 0)
    {
        global $wpdb;

        // SECURITY FIX: Whitelist allowed columns for ORDER BY to prevent SQL injection
        $orderCol = array(
            'uid' => 'uid',
            'user_login' => 'user_login',
            'time' => 'time',
            'ip' => 'ip'
        );
        
        // SECURITY FIX: Whitelist allowed directions for ORDER BY to prevent SQL injection
        $orderDir = array(
            'asc' => 'ASC',
            'desc'=> 'DESC'
        );

        $where = '';

        $orderby = isset($orderCol[$orderby]) ? $orderCol[$orderby] : 'time';
        $order   = isset($orderDir[$order]) ? $orderDir[$order] : 'DESC';

        $where = $this->make_where_query();

        if( is_array($where) && !empty($where) )
            $where = ' WHERE ' . implode(' AND ', $where);

        // SECURITY FIX: Use properly escaped and validated ORDER BY clause
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, $where uses esc_sql(), $orderby and $order validated against whitelist
            "SELECT * FROM {$this->table}{$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared above with $wpdb->prepare()
        $data = $wpdb->get_results($sql, 'ARRAY_A');

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
                    $where[$k] = @esc_attr($v);
                }
                echo '<form method="get" id="export-login-log">';
                if ( function_exists('wp_nonce_field') )
                    wp_nonce_field('ssl_export_log');

                echo '<input type="hidden" name="page" value="login_log" />';
                echo '<input type="hidden" name="download-login-log" value="true" />';
                echo '<input type="hidden" name="where" value="' . esc_attr(serialize($where)) . '" />';
                submit_button( __('Export Current Results to CSV', 'simple-login-log'), 'secondary' );
                echo '</form>';

            }

        echo '</div>';
    }


    function date_filter()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, not user input
        $sql = "SELECT DISTINCT YEAR(time) as year, MONTH(time)as month FROM {$this->table} ORDER BY YEAR(time), MONTH(time) desc";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input, table name is internal property
        $results = $wpdb->get_results($sql);

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

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, serialized filter data will be sanitized after unserialization
            $where = ( isset($_GET['where']) && '' != $_GET['where'] ) ? wp_unslash( $_GET['where'] ) : false;
            $where = maybe_unserialize( stripcslashes($where) );

            if( is_array($where) && !empty($where) )
            {
                foreach($where as $k => $v)
                {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above, values already sanitized in $where array
                    $_GET[$k] = sanitize_text_field($v);
                }
            }

            $this->export_to_CSV( $this->make_where_query() );
        }
    }


    function export_to_CSV($where = false){
        global $wpdb;

        //if $where is set, then contemplate WHERE sql query
        if( $where ){

            if( is_array($where) && !empty($where) )
                $where = ' WHERE ' . implode(' AND ', $where);

        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, $where uses esc_sql() from make_where_query()
        $sql = "SELECT * FROM {$this->table}{$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where clause is built with esc_sql() in make_where_query()
        $data = $wpdb->get_results($sql, 'ARRAY_A');

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
            // FIX: Replace deprecated create_function with anonymous function
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


    function get_views()
    {
        //creating class="current" variables
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading result parameter for display, read-only
        if( !isset($_GET['result']) ){
            $all = 'class="current"';
            $success = '';
            $failed = '';
        }else{
            $all = '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading result parameter for display, read-only
            $result_value = isset($_GET['result']) ? sanitize_text_field( wp_unslash( $_GET['result'] ) ) : '';
            $success = ( '1' === $result_value ) ? 'class="current"' : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading result parameter for display, read-only
            $failed = ( '0' === $result_value ) ? 'class="current"' : '';
        }



        //if date filter is set, adjust views label to reflect the date
        $date_label = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading datefilter parameter for display, read-only
        if( isset($_GET['datefilter']) && !empty($_GET['datefilter']) ){
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter, already checked with isset above
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


    function prepare_items()
    {
        global $wpdb, $sll;

        //get number of successful and failed logins so we can display them in parentheces for each view

        //building a WHERE SQL query for each view
        $where = $sll->make_where_query();
        //we only need the date filter, everything else need to be unset
        if( is_array($where) && isset($where['datefilter']) ){
            $where = array( 'datefilter' =>  $where['datefilter'] );
        }else{
            $where = false;
        }

        $where3 = $where2 = $where1 = $where;
        $where2['login_result'] = "login_result = '1'";
        $where3['login_result'] = "login_result = '0'";

        if(is_array($where1) && !empty($where1)){
            $where1 = 'WHERE ' . implode(' AND ', $where1);
        }
        $where2 = 'WHERE ' . implode(' AND ', $where2);
        $where3 = 'WHERE ' . implode(' AND ', $where3);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, $where uses esc_sql()
        $sql1 = "SELECT count(*) FROM {$sll->table} {$where1}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where clauses are built with esc_sql() in make_where_query()
        $allTotal = $wpdb->get_var($sql1);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, $where uses esc_sql()
        $sql2 = "SELECT count(*) FROM {$sll->table} {$where2}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where clauses are built with esc_sql() in make_where_query()
        $successTotal = $wpdb->get_var($sql2);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal property, $where uses esc_sql()
        $sql3 = "SELECT count(*) FROM {$sll->table} {$where3}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where clauses are built with esc_sql() in make_where_query()
        $failedTotal = $wpdb->get_var($sql3);

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
         * Optional. You can handle your bulk actions however you see fit. In this
         * case, we'll handle them within our package just to keep things clean.
         */
        //$this->process_bulk_action();


        /**
         * Instead of querying a database, we're going to fetch the example data
         * property we created for use in this plugin. This makes this example
         * package slightly different than one you might build on your own. In
         * this example, we'll be using array manipulation to sort and paginate
         * our data. In a real-world implementation, you will probably want to
         * use sort and pagination data to build a custom query instead, as you'll
         * be able to use your precisely-queried data immediately.
         */
//        $data = $this->items;


        /**
         * This checks for sorting input and sorts the data in our array accordingly.
         *
         * In a real-world situation involving a database, you would probably want
         * to handle sorting by passing the 'orderby' and 'order' values directly
         * to a custom query. The returned data will be pre-sorted, and this array
         * sorting technique would be unnecessary.
         */
//        function usort_reorder($a,$b)
//        {
//            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'time'; //If no sort, default to title
//            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; //If no order, default to asc
//            $result = strcmp($a[$orderby], $b[$orderby]); //Determine sort order
//            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
//        }
//        usort($data, 'usort_reorder');


        /***********************************************************************
         * ---------------------------------------------------------------------
         * vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
         *
         * In a real-world situation, this is where you would place your query.
         *
         * ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         * ---------------------------------------------------------------------
         **********************************************************************/


        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently
         * looking at. We'll need this later, so you should always include it in
         * your own package classes.
         */
        $current_page = $this->get_pagenum();

        /**
         * REQUIRED for pagination. Let's check how many items are in our data array.
         * In real-world use, this would be the total number of items in your database,
         * without filtering. We'll need this later, so you should always include it
         * in your own package classes.
         */

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading result parameter for pagination count, read-only
        $result_filter = isset($_GET['result']) ? sanitize_text_field( wp_unslash( $_GET['result'] ) ) : '';
        if ( '1' === $result_filter )
        {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where2 is built with esc_sql() in make_where_query()
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $sll->table {$where2}");
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading result parameter for pagination count, read-only
        else if( '0' === $result_filter )
        {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where3 is built with esc_sql() in make_where_query()
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $sll->table {$where3}");
        }
        else
        {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input, table property is internal
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $sll->table");
        }


        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to
         */
//        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);



        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where
         * it can be used by the rest of the class.
         */
//        $this->items = $data;


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

