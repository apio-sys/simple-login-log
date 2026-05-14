<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/*
  Plugin Name: Simple Login Log
  Plugin URI: https://apio.systems
  Description: This plugin keeps a log of WordPress user logins. Offers user filtering and export features.
  Version: 2.0.2
  Author: Joris Le Blansch
  Author URI: https://apio.systems
  License: MIT
  License URI: https://github.com/apio-sys/simple-login-log/blob/main/LICENSE
  Text Domain: simple-login-log
  Requires at least: 6.5
  Requires PHP: 7.4
 */

if( !class_exists( 'SimpleLoginLog' ) )
{

 class SimpleLoginLog
 {
    const VERSION = '2.0.2';
    private $db_ver = "1.3";
    public $table = 'simple_login_log';
    private $log_duration = null; //days
    private $opt_name = 'simple_login_log';
    private $opt = false;
    private $login_success = 1;
    public $data_labels = array();
    private $installed_ver = null;
    private $values;
    private $log_table = null;

    function __construct()
    {
        global $wpdb;

        if ( is_multisite() )
        {
            $main_prefix = $wpdb->get_blog_prefix(1);
            $this->table = $main_prefix . $this->table;
        }
        else
        {
            $this->table = $wpdb->prefix . $this->table;
        }
        $this->opt = get_option($this->opt_name);
        
        if ( ! is_array( $this->opt ) ) {
            $this->opt = array();
        }

        $this->installed_ver = get_option( "sll_db_ver" );

        add_action( 'admin_menu', array($this, 'sll_admin_menu') );
        add_action('admin_init', array($this, 'settings_api_init') );
        add_action('admin_head', array($this, 'screen_options') );

        add_action('plugins_loaded', array($this, 'update_db_check') );

        add_action( 'init', array($this, 'load_textdomain') );
        add_action( 'init', array($this, 'init_login_actions') );

        add_action('admin_init', array($this, 'init_csv_export') );
        add_action('admin_init', array($this, 'delete_all') );

        add_action( 'admin_enqueue_scripts', array($this, 'admin_enqueue_styles') );

        add_action( 'wp', array($this, 'init_scheduled_events') );
        add_action('truncate_sll', array($this, 'cron') );

        register_deactivation_hook(__FILE__, array($this, 'deactivation') );

    }

    function load_textdomain()
    {
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = ( isset($_GET['page']) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : false;
        if( 'login_log' != $page )
            return;

        $current_screen = get_current_screen();

        $per_page_field = 'per_page';
        $per_page_option = $current_screen->id . '_' . $per_page_field;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if( isset($_REQUEST['wp_screen_options']) && isset($_REQUEST['wp_screen_options']['value']) )
        {
            update_option( $per_page_option, absint( wp_unslash( $_REQUEST['wp_screen_options']['value'] ) ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $per_page_val = get_option($per_page_option, 20);
        $args = array('label' => __('Records', 'simple-login-log'), 'default' => $per_page_val );

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
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE time < DATE_SUB(CURDATE(), INTERVAL %d DAY)',
                    $this->table,
                    $log_duration
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
         $result = $wpdb->query(
             $wpdb->prepare( 'DELETE FROM %i WHERE 1 = %d', $this->table, 1 )
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $table_exists = $wpdb->get_var(
                $wpdb->prepare( "SHOW TABLES LIKE %s", $this->table )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            
            if( !$table_exists ){
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $fields = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i LIMIT %d', $this->table, 1 ),
            'ARRAY_A'
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('login_result', $field_names) )
        {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $insert = $wpdb->query(
                $wpdb->prepare(
                    'ALTER TABLE %i ADD COLUMN login_result varchar(1) NOT NULL AFTER ip, ADD INDEX (login_result)',
                    $this->table
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $fields = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i LIMIT %d', $this->table, 1 ),
            'ARRAY_A'
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if( !$fields ){
            $this->install();
            return;
        }

        $field_names = array_keys( $fields );

        if( !array_search('user_role', $field_names) )
        {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $insert = $wpdb->query(
                $wpdb->prepare(
                    'ALTER TABLE %i ADD COLUMN user_role varchar(30) NOT NULL AFTER user_login',
                    $this->table
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

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

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
         $fields = $wpdb->get_row(
             $wpdb->prepare( 'SELECT * FROM %i LIMIT %d', $this->table, 1 ),
             'ARRAY_A'
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

         if( !$fields ){
             $this->install();
             return;
         }

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
         $insert = $wpdb->query(
             $wpdb->prepare(
                 'ALTER TABLE %i MODIFY user_role varchar(255) NOT NULL',
                 $this->table
             )
         );
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

    /**
     * Enqueue admin styles for the login log page.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    function admin_enqueue_styles( $hook_suffix )
    {
        // Only load on the login_log page (under Users menu)
        if ( 'users_page_login_log' !== $hook_suffix ) {
            return;
        }

        wp_register_style( 'simple-login-log-admin', false, array(), self::VERSION );
        wp_enqueue_style( 'simple-login-log-admin' );
        wp_add_inline_style( 'simple-login-log-admin', 'table.users { table-layout: auto; }' );
    }

    //Catch messages on successful login
    function login_action($user_login)
    {

        $userdata = get_user_by('login', $user_login);

        $uid = ($userdata && $userdata->ID) ? $userdata->ID : 0;

        $data[$this->data_labels['Login']] = ( 1 == $this->login_success ) ? $this->data_labels['Successful'] : $this->data_labels['Failed'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_REQUEST['redirect_to'] ) ) { $data[$this->data_labels['Login Redirect']] = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ); }
        $data[$this->data_labels['User Agent']] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $serialized_data = serialize($data);

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( $this->table, $values, $format );
    }

    /**
     * Build filter data for WHERE clause.
     * Returns sanitized filter values that can be used to build queries.
     *
     * @return array{filter: string|false, user_role: string|false, result: string|false, year: int|false, month: int|false}
     */
    function get_filter_values()
    {
        $filters = array(
            'filter'    => false,
            'user_role' => false,
            'result'    => false,
            'year'      => false,
            'month'     => false,
        );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset($_GET['filter']) && '' !== $_GET['filter'] ) {
            $filters['filter'] = sanitize_text_field( wp_unslash( $_GET['filter'] ) );
        }
        if ( isset($_GET['user_role']) && '' !== $_GET['user_role'] ) {
            $filters['user_role'] = sanitize_text_field( wp_unslash( $_GET['user_role'] ) );
        }
        if ( isset($_GET['result']) && '' !== $_GET['result'] ) {
            $filters['result'] = sanitize_text_field( wp_unslash( $_GET['result'] ) );
        }
        if ( isset($_GET['datefilter']) && '' !== $_GET['datefilter'] ) {
            $datefilter = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            // Validate format: YYYYMM
            if ( preg_match( '/^(\d{4})(\d{2})$/', $datefilter, $matches ) ) {
                $filters['year'] = (int) $matches[1];
                $filters['month'] = (int) $matches[2];
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $filters;
    }

    /**
     * Get date filter values only (used for stats).
     *
     * @return array{year: int|false, month: int|false}
     */
    function get_datefilter_values()
    {
        $filters = array(
            'year'  => false,
            'month' => false,
        );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset($_GET['datefilter']) && '' !== $_GET['datefilter'] ) {
            $datefilter = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            // Validate format: YYYYMM
            if ( preg_match( '/^(\d{4})(\d{2})$/', $datefilter, $matches ) ) {
                $filters['year'] = (int) $matches[1];
                $filters['month'] = (int) $matches[2];
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $filters;
    }

    /**
     * Check if any filters are active.
     *
     * @return bool
     */
    function has_active_filters()
    {
        $filters = $this->get_filter_values();
        return ( $filters['filter'] !== false || $filters['user_role'] !== false || 
                 $filters['result'] !== false || $filters['year'] !== false );
    }

    /**
     * Get total count of records with current filters applied.
     *
     * @return int
     */
    function get_total_filtered_count()
    {
        global $wpdb;

        $filters = $this->get_filter_values();
        $table = $this->table;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['result'], $filters['year'], $filters['month'])
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s",
                    $table, $filter_like, $filter_like, $role_like, $filters['result'])
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['year'], $filters['month'])
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $filters['result'], $filters['year'], $filters['month'])
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $role_like, $filters['result'], $filters['year'], $filters['month'])
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s",
                    $table, $filter_like, $filter_like, $role_like)
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s",
                    $table, $filter_like, $filter_like, $filters['result'])
            );
        } elseif ( $filters['filter'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $filters['year'], $filters['month'])
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE user_role LIKE %s AND login_result = %s",
                    $table, $role_like, $filters['result'])
            );
        } elseif ( $filters['user_role'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $role_like, $filters['year'], $filters['month'])
            );
        } elseif ( $filters['result'] !== false && $filters['year'] !== false ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filters['result'], $filters['year'], $filters['month'])
            );
        } elseif ( $filters['filter'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE (user_login LIKE %s OR ip LIKE %s)",
                    $table, $filter_like, $filter_like)
            );
        } elseif ( $filters['user_role'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE user_role LIKE %s",
                    $table, $role_like)
            );
        } elseif ( $filters['result'] !== false ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE login_result = %s",
                    $table, $filters['result'])
            );
        } elseif ( $filters['year'] !== false ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filters['year'], $filters['month'])
            );
        } else {
            $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $count;
    }

    function getLimit()
    {
        return ' LIMIT ' . get_option('users_page_login_log_per_page', 20);
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    function log_get_data($orderby = false, $order = false, $limit = 0, $offset = 0)
    {
        global $wpdb;

        // $order and $orderby are validated against strict whitelists
        $allowed_columns = array( 'uid', 'user_login', 'time', 'ip' );
        $orderby = in_array( $orderby, $allowed_columns, true ) ? $orderby : 'time';
        $order = ( 'asc' === strtolower( $order ) ) ? 'ASC' : 'DESC';

        $filters = $this->get_filter_values();
        $table = $this->table;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['result'], $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['result'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $filters['result'], $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $role_like, $filters['result'], $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $role_like, $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $filters['result'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND login_result = %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $role_like, $filters['result'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $role_like, $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['result'] !== false && $filters['year'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filters['result'], $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filter_like, $filter_like, $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $role_like, $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['result'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE login_result = %s ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filters['result'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } elseif ( $filters['year'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $filters['year'], $filters['month'], $orderby, $limit, $offset),
                'ARRAY_A'
            );
        } else {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i ORDER BY %i {$order} LIMIT %d OFFSET %d",
                    $table, $orderby, $limit, $offset),
                'ARRAY_A'
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $this->date_filter();
                echo '</div>';

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

                if( isset($this->opt['failed_attempts']) ){
                    echo '<div class="alignleft actions">';
                            $log_table->views();
                    echo '</div>';
                }

                echo '<div class="alignright actions">';
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if( isset( $_GET['filter'] ) || isset( $_GET['user_role'] ) || isset( $_GET['datefilter'] ) || isset( $_GET['result'] ) )
            {
                $where = array();
                foreach($_GET as $k => $v)
                {
                    $where[ sanitize_key( $k ) ] = sanitize_text_field( $v );
                }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DISTINCT YEAR(time) as year, MONTH(time) as month FROM %i ORDER BY YEAR(time), MONTH(time) DESC',
                $this->table
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if(!$results)
            return '';

        $option = '';
        foreach($results as $row)
        {
            $timestamp = mktime(0, 0, 0, $row->month, 1, $row->year);
            $month = (strlen($row->month) == 1) ? '0' . $row->month : $row->month;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $download = (isset($_GET['download-login-log'])) ? sanitize_text_field( wp_unslash( $_GET['download-login-log'] ) ) : false;

        if($download)
        {
            check_admin_referer( 'ssl_export_log' );

            $where_json = ( isset($_GET['where']) && '' !== $_GET['where'] ) ? sanitize_text_field( wp_unslash( $_GET['where'] ) ) : false;
            
            if ( $where_json ) {
                $where = json_decode( $where_json, true );
                if ( is_array( $where ) ) {
                    foreach( $where as $k => $v ) {
                        $_GET[ sanitize_key( $k ) ] = sanitize_text_field( $v );
                    }
                }
            }

            $this->export_to_CSV( $this->get_filter_values() );
        }
    }

    function export_to_CSV( $filters = false ){
        global $wpdb;

        $table = $this->table;

        if ( ! $filters ) {
            $filters = array(
                'filter'    => false,
                'user_role' => false,
                'result'    => false,
                'year'      => false,
                'month'     => false,
            );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['result'], $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND login_result = %s",
                    $table, $filter_like, $filter_like, $role_like, $filters['result']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $role_like, $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $filters['result'], $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $role_like, $filters['result'], $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['user_role'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND user_role LIKE %s",
                    $table, $filter_like, $filter_like, $role_like),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['result'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND login_result = %s",
                    $table, $filter_like, $filter_like, $filters['result']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false && $filters['year'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s) AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filter_like, $filter_like, $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['result'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND login_result = %s",
                    $table, $role_like, $filters['result']),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false && $filters['year'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $role_like, $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['result'] !== false && $filters['year'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE login_result = %s AND YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filters['result'], $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } elseif ( $filters['filter'] !== false ) {
            $filter_like = '%' . $wpdb->esc_like( $filters['filter'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE (user_login LIKE %s OR ip LIKE %s)",
                    $table, $filter_like, $filter_like),
                'ARRAY_A'
            );
        } elseif ( $filters['user_role'] !== false ) {
            $role_like = '%' . $wpdb->esc_like( $filters['user_role'] ) . '%';
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE user_role LIKE %s",
                    $table, $role_like),
                'ARRAY_A'
            );
        } elseif ( $filters['result'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE login_result = %s",
                    $table, $filters['result']),
                'ARRAY_A'
            );
        } elseif ( $filters['year'] !== false ) {
            $data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $filters['year'], $filters['month']),
                'ARRAY_A'
            );
        } else {
            // No filters - get all records
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $data = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), 'ARRAY_A' );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if(!$data)
            return;

        $suffix = wp_date('Ymd_Hi');

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment;filename=simple-login-log_' . $suffix . '.csv');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fp = fopen('php://output', 'w');

        $i = 0;
        foreach($data as $row){
            $tmp = unserialize($row['data']);
            if(0 == $i)
            {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
                fputcsv( $fp, array_keys($row), ',', '"', '\\' );
            }
            $row_data = (!empty($tmp)) ? array_map(function($key, $value) {
                return $key.": ".$value." | ";
            }, array_keys($tmp), array_values($tmp)) : array();
            $row['data'] = implode($row_data);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
            fputcsv($fp, $row, ',', '"', '\\');
            $i++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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

                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

        $date_label = '';
        if( isset($_GET['datefilter']) && !empty($_GET['datefilter']) ){
            $datefilter_value = sanitize_text_field( wp_unslash( $_GET['datefilter'] ) );
            $year = substr($datefilter_value, 0, 4);
            $month = substr($datefilter_value, -2);
            $timestamp = mktime(0, 0, 0, $month, 1, $year);
            $date_label = esc_html(date_i18n('F', $timestamp)) . ' ' . esc_html($year) . ' ';
        }

        $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $args = wp_parse_args( wp_parse_url( $current_url, PHP_URL_QUERY ) );
        $param = array();
        if( isset($args['mode']) )
            $param['mode'] = $args['mode'];

        if( isset($args['datefilter']) )
            $param['datefilter'] = $args['datefilter'];

        $menu_page_url = menu_page_url('login_log', false);
        ( !empty($param) ) ? $url = add_query_arg( $param, $menu_page_url) : $url = $menu_page_url;

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

        $table = $sll->table;

        $date_filters = $sll->get_datefilter_values();
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $date_filters['year'] !== false ) {
            $allTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d",
                    $table, $date_filters['year'],
                    $date_filters['month'])
            );
            $successTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d AND login_result = %s",
                    $table, $date_filters['year'],
                    $date_filters['month'],
                    '1')
            );
            $failedTotal = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE YEAR(time) = %d AND MONTH(time) = %d AND login_result = %s",
                    $table, $date_filters['year'],
                    $date_filters['month'],
                    '0')
            );
        } else {
            $allTotal = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
            $successTotal = $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE login_result = %s', $table, '1' )
            );
            $failedTotal = $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE login_result = %s', $table, '0' )
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = (isset($_REQUEST['orderby']) && !empty($_REQUEST['orderby'])) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
        $total_items = $sll->get_total_filtered_count();

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
