<?php

/*
Plugin Name: RSS Feed Fetch
Description: Fetches RSS feeds and saves posts through a wp-cli command.
Author: Alain Jacomet
Version: 1.0.0
*/

if (!class_exists('RSSFeed')):

class RSSFeed {
    /***
     * The settings variable is configurable on a per-instance case and will be saved automatically to the database
     * after everything is done.
     *
     * # Configuring an instance
     *
     * ```
     * $rss = new RSSFeed();
     * $rss->settings['post_status'] = 'draft';
     * $rss->add('http://feed.url/');
     * $rss->fetch();
     * ```
     *
     * @var array
     */
    public  $settings;


    /***
     * Default settings to be used internally in case there is no option on the database specifying how to run or no
     * custom settings have been defined in the instance.
     *
     * @var array
     */
    private $default_settings = array(
        'feeds'             => array( 'http://allvoices.com/hub/118/feed.xml' ), // An array of default feeds
        'post_category'     => array( 0 ), // The category to assign to posts (empty array for none)
        'post_author'       => 1, // The author to assign to posts
        'post_status'       => 'draft', // The post status after it has been imported
        'enclosures'        => true, // Process enclosures

        '_times_run'        => 0, // Internal: amount of times it has run
        '_managed_posts'    => array( ), // Internal: Ids of the posts currently managed
        '_last_run'         => 0, // Internal: date of the last run
        '_option_name'      => 'rssff_options' // Internal: option name on the database
    );

    public function __construct() {
        // Fetch the settings and place them as an instance variable later use
        $this->settings = $this->getConfiguration();

        $this->log('Initialized RSSFeed object');
    }

    public function __destruct() {
        $this->log('Destructing RSSFeed object');
    }



    /***
     * A stub that sanitizes an option before setting
     * @return string
     */
    public function sanitizeOption($option, $value) {
        return json_decode($value);
    }



    /***
     * Configurable logging function (to configure when using with WPCLI)
     * @param string $message
     * @return bool
     */
    public function log($message = '') {
        do_action('rsff_log', $message);
        return true;
    }

    /***
     * Clear the settings from the database
     *
     * @return bool
     */
    public function clear_settings() {
        $option = $this->settings['_option_name'];

        $return = delete_option($option);

        if ($return == true)
            $this->log('Cleared all settings');
        else
            $this->log('Failed to clear settings');

        return $return;
    }

    /***
     * Save to the database and finish
     *
     * @return bool
     */
    public function saveAndExit() {
        $settings = $this->settings;

        $settings['_times_run']++;
        $settings['_last_run'] = time();

        return $this->saveConfiguration($settings);
    }

    /***
     * Save the settings object to the database 
     * @return bool
     */ 

    public function saveConfiguration($settings) {
        $settings = $this->settings;
        $option = $settings['_option_name'];

        return update_option($option, $settings);
    }

    /***
     * Adds a new feed to the process queue. Returns the new number of feeds.
     *
     * @param $feed_uri
     * @return int
     */
    public function add($feed_uri) {

        $return = array_push($this->settings['feeds'], $feed_uri);

        if ($return > 0)
            $this->log('Successfully added ' . $feed_uri);
        else
            $this->log('Failure to add feed');

        $this->saveAndExit();

        return $return;
    }


    /***
     * Returns the feeds array
     * @return mixed
     */
    public function get_feeds() {
        $settings = $this->settings;

        return $settings['feeds'];
    }

    /***
     * Returns the codes to be used for managing the posts
     *
     * @param $feed_index
     * @return array
     */
    public function get_codes($feed_index) {

        $return = array();
        $posts = $this->fetch($feed_index);

        foreach ($posts as $post):
            $return[$post['post_meta']['_rssff_id']] = $post['post_title'];
        endforeach;

        return $return;
    }

    /***
     * Remove a feed from it's index, returns the removed item
     *
     * @param int $feed_index
     * @return array
     */
    public function remove($feed_index = 0) {
        $feeds = $this->get_feeds();
        $feed_uri = $feeds[$feed_index];
        $return = array_splice($this->settings['feeds'], $feed_index, 1);

        if (is_array($return))
            $this->log('Successfully removed ' . $feed_uri);
        else
            $this->log('Failure to remove feed');

        $this->saveAndExit();

        return !!$return;
    }

    /***
     * Fetches a feed and returns an array of posts
     * @return array
     */
    public function fetch( $feed_index ) {
        $return = array();
        $settings = $this->settings;
        $feeds = $this->get_feeds();
        $feed_uri = isset($feeds[$feed_index])?$feeds[$feed_index]:false;

        $return = apply_filters('rssff_fetch_feed', $return, $feed_uri, $settings);

        $this->log("Fetched " . count($return) . " posts.");

        return $return;
    }

    /***
     * Insert post to the wordpress database
     *
     * @param $post_data
     * @return int
     */
    public function post($post_data) {

        $post_id = $this->find_managed_post($post_data['post_meta']['_rssff_id']);

        $post_meta = $post_data['post_meta'];

        if ( ! $post_id ):

            $post_id = wp_insert_post($post_data);

        else:

            $post_data['ID'] = $post_id;
            //$post_id = wp_update_post($post_data);            

        endif;

        if (!has_post_thumbnail($post_id) && isset($post_meta['_rssff_image'])):
            $attachment_id = $this->save_image($post_id, $post_meta['_rssff_image']);
            $saved_image = update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
        endif;  

        $this->log("Updated post id: $post_id. " . ($saved_image ? "Attachment id: " . $attachment_id: 'Didn\'t set attachment') );
        $post_meta =  $post_data['post_meta'];

        if ( $post_meta ):

            foreach ($post_meta as $meta_key => $meta_value):
                if (! add_post_meta($post_id, $meta_key, $meta_value, true) )
                    update_post_meta($post_id, $meta_key, $meta_value);
            endforeach;

        endif;

        return !!$post_id;

    }

    /**
     * Fetches a single feed and saves the posts to the database
     *
     * @param $feed_index
     * @return bool
     */
    public function fetch_and_save($feed_index) {
        $posts = $this->fetch($feed_index);

        foreach ($posts as $post):
            if (!$this->post($post))
                return false;
        endforeach;

        return true;
    }

    /**
     * Fetches all feeds and saves posts
     * @return bool
     */
    public function fetch_and_save_all() {
        $feeds = $this->get_feeds();

        foreach (array_keys($feeds) as $feed_index):
            if (! $this->fetch_and_save($feed_index))
                return false;
        endforeach;

        return true;
    }

    /***
     * Fetches the configuration parameters
     *
     * @return array
     */
    public function getConfiguration() {

        // Fetch the defaults and the option from the database
        $defaults = $this->default_settings;
        $option = $defaults['_option_name'];
        $settings = get_option($option);

        // Make the option if it not present
        if (!$settings):
            add_option($option);
            update_option($option, $defaults);
        endif;

        return $settings;
    }

    /***
     * Returns false if it wasn't created previously, ID otherwise
     * @param $private_key
     * @return mixed
     */
    public function find_managed_post($private_key) {

        $settings = $this->settings;

        if ( !isset($settings['_managed_posts']) )
            $this->settings['_managed_posts'] = array();

        if ( isset($settings['_managed_posts'][$private_key]) )
            return $this->settings['_managed_posts'][$private_key];

        $query_args = array(
            'post_type'     => 'post',
            'meta_key'      => '_rssff_id',
            'meta_value'    => $private_key,
            'post_status'   => 'any'

        );

        $posts = (array)get_posts($query_args);

        if ( count($posts) ):
            $post_id = $posts[0]->ID;
            $this->settings['_managed_posts'][$private_key] = $post_id;

            return $post_id;
        endif;

        return false;
    }

    /***
     * Set the featured image for the post
     *
     * @param $post_id
     * @param $image_url
     * @return bool
     */
    private function save_image($post_id, $image_url) {

        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        // Get the file extension for the image
        $file_extension = image_type_to_extension( exif_imagetype( $image_url ) );

        // Save as a temporary file
        $tmp = download_url( $image_url );

        // Check for download errors
        if ( is_wp_error( $tmp ) )
        {
            throw new Exception($tmp->get_error_message());
            return $tmp;
        }

        // Image base name:
        $name = basename( $image_url );

        // Take care of image files without extension:
        $path = pathinfo( $image_url );

        if( ! isset( $path['extension'] ) ):
            $name = pathinfo( $image_url, PATHINFO_FILENAME )  . $file_extension;
        endif;

        // Upload the image into the WordPress Media Library:
        $file_array = array(
            'name'     => $name,
            'tmp_name' => $tmp
        );

        $attachment_id = media_handle_sideload( $file_array, 0 );

        // Check for handle sideload errors:
        if ( is_wp_error( $attachment_id ) )
        {

            @unlink( $file_array['tmp_name'] );
            throw new Exception($attachment_id->get_error_message());
            return $attachment_id;
        }

        // Set the attachment to the post
        return $attachment_id;
    }
}

if (!function_exists('rsff_default_author_name')) {
    function rsff_default_author_name($feed_item) {
        $author_data = $feed_item->get_author();

        $author_email = $author_data->get_email();
        $author_link = $author_data->get_link();
        $author_name = $author_data->get_name();

        // Extra for Allvoices
        return (string) $author_email;
    }
}


if (!function_exists('rssff_default_image')) {
    function rssff_default_image($post_to_insert, $feed_item) {

        $enclosure = $feed_item->get_enclosure();
        $raw_link = $enclosure->get_link();

        // Extra for Allvoices
        if (substr($raw_link, 0, 28) == 'http://www.allvoices.comhttp') $raw_link = substr($raw_link, 24);

        $post_to_insert['post_meta']['_rssff_image'] = $raw_link;
        //echo $raw_link;
        return $post_to_insert;
    }
}

add_filter('rssff_fetch_post', 'rssff_default_image', 10, 2);

if (!function_exists('rssff_default_item_content')) {
    function rssff_default_item_content($feed_item)
    {
        $html_content = $feed_item->get_content();

        // Extra for Allvoices
        $extra_content = $feed_item->get_item_tags('', 'comments');
        if (isset($extra_content[0]['child']['']['script'][0]['attribs']['']['src']))
            $html_content .= "<script id='ppixel' type='text/javascript' src='" . $extra_content[0]['child']['']['script'][0]['attribs']['']['src'] . "'></script>";

        return (string)$html_content;
    }
}


if (!function_exists('rssff_default_fetch')) {
    function rssff_default_fetch($return, $feed_uri, $settings)
    {

        require_once(ABSPATH . WPINC . '/feed.php');
        $rss = fetch_feed($feed_uri);

        $maxitems = 0;

        if (!is_wp_error($rss)) :
            $maxitems = $rss->get_item_quantity();
            $rss_items = $rss->get_items(0, $maxitems);
        endif;

        if ($maxitems != 0):

            foreach ($rss_items as $item):

                $post_to_insert = array();

                // Standard
                $post_to_insert['post_title'] = esc_html($item->get_title()); // The title of the post
                $post_to_insert['post_date'] = $item->get_date('Y-m-d H:i:s'); // The date of the post
                $post_to_insert['post_content'] = rssff_default_item_content($item); // The content
                $post_to_insert['post_category'] = $settings['post_category']; // The category to assign
                $post_to_insert['post_author'] = $settings['post_author']; // The post author to assign
                $post_to_insert['post_status'] = $settings['post_status']; // The post status to assign

                // Meta
                $post_to_insert['post_meta'] = array();
                $post_to_insert['post_meta']['rssff_author'] = rsff_default_author_name($item);
                $post_to_insert['post_meta']['author_name'] = $post_to_insert['post_meta']['rssff_author'];
                $post_to_insert['post_meta']['_rssff_id'] = md5($item->get_id());
                $post_to_insert['post_meta']['_rssff_source'] = $feed_uri;

                $post_to_insert = apply_filters('rssff_fetch_post', $post_to_insert, $item);
                //var_dump($post_to_insert);
                array_push($return, $post_to_insert);
            endforeach;
        endif;

        return $return;
    }
}
add_filter('rssff_fetch_feed', 'rssff_default_fetch', 10, 3);


if ( defined('WP_CLI') && WP_CLI ) {

    /**
     * WP-CLI command to fetch feeds and save them as posts.
     */
    class RSSFeed_Command extends WP_CLI_Command {

        public $rss;

        public function __construct() {
            add_action('rsff_log', function($message) {
                WP_CLI::line(WP_CLI::colorize('%bLog:%c ' . $message . '%n'));
            });

            $this->rss = new RSSFeed();
        }

        /**
         * Lists all feeds
         * @subcommand feed-list
         */
        public function _list($args) {
            $rss = $this->rss;

            $feeds = $rss->get_feeds();

            foreach (array_keys((array)$feeds) as $feed_index):
                WP_CLI::line("- $feed_index: $feeds[$feed_index]");
            endforeach;

            return $feeds;
        }

        /**
         * Deletes a feed
         *
         * ## OPTIONS
         *
         * <feed-index>
         * : The id of the feed as seen in wp rss list.
         *
         * @subcommand feed-delete
         */
        public function _remove($args) {
            $rss = $this->rss;
            list( $feed_id ) = $args;

            $return = $rss->remove($feed_id);

            return $return;

        }

        /**
         * Adds a feed to the settings
         *
         * ## OPTIONS
         *
         * <feed-uri>
         * : The URI of the feed to add. It will not be checked so make sure it works!
         *  
         * @subcommand feed-add
         */
        public function _add($args) {
            $rss = $this->rss;
            list( $feed_uri ) = $args;

            $return = $rss->add($feed_uri);

            return $return;
        }

        /**
         * Fetches and saves a feed
         *
         * ## OPTIONS
         *
         * <feed-id>
         * : The id of the feed as seen in wp rss list.
         *
         * @synopsis [<feed-index>]
         * @subcommand feed-fetch
         */
        public function _fetch($args) {
            $rss = $this->rss;
            list( $feed_index ) = $args;

            if ($feed_index)
                return $rss->fetch_and_save($feed_index);
            else
                return $rss->fetch_and_save_all();
        }

        /**
         * Resets the settings for a fresh start
         *
         * @subcommand reset-database
         */
        public function _reset($args) {
            $rss = $this->rss;

            $return = $rss->clear_settings();

            return $return;
        }

        /**
         * Retrieves the codes to be used on the feeds
         *
         * ## OPTIONS
         *
         * <feed-id>
         * : The id of the feed as seen in wp rss list.
         *
         * @synopsis <feed-index>
         * @subcommand get-codes
         */
        public function _codes($args) {
            $rss = $this->rss;
            list( $feed_index ) = $args;

            $codes = $rss->get_codes($feed_index);

            foreach ($codes as $code => $post_title):
                $managed_id = $rss->find_managed_post($code);
                $managed_in_database = !!$managed_id ? "Managed ($managed_id)":"Not managed";

                WP_CLI::line("- $code: $managed_in_database: $post_title");
            endforeach;

            return $codes;
        }

        /**
         * Removes all managed posts
         *
         * ## OPTIONS
         *
         * <feed-id>
         * : The id of the feed as seen in wp rss list.
         *
         * @synopsis <feed-index>
         * @subcommand remove-posts
         */
        public function _remove_posts($args) {
            $rss = $this->rss;
            list( $feed_index ) = $args;

            $codes = $rss->get_codes($feed_index);

            foreach ($codes as $code => $post_title):
                $managed_id = $rss->find_managed_post($code);
                
                if ( wp_delete_post( $managed_id, true ) )
                    WP_CLI::line("- successfully deleted post $managed_id");
                else 
                    WP_CLI::line("- error deleting post $managed_id");
            endforeach;

            return $codes;
        }


        /**
         * Configure
         *
         * @subcommand configure
         */
        public function _configure($args, $assoc_args) {
            $rss = $this->rss;
            $settings = $rss->getConfiguration();

            if (array_key_exists('show', $assoc_args)) {
                foreach ($settings as $setting => $value) {
                    WP_CLI::line("$setting: " . json_encode($value) );
                }

                return $settings;
            }


            foreach ($assoc_args as $parameter => $value) {

                if (!isset($settings[$parameter])) {
                    throw new Exception('There is no parameter by the name ' . $parameter);
                }

                else {
                    
                    $val = $rss->sanitizeOption($parameter, $value);
                    
                    $settings[$parameter] = $val;


                    WP_CLI::line("Successfully changed $parameter to $val" );
                }

            }

            
            $rss->saveConfiguration($settings);

            return $settings;
            
        }
    }

    WP_CLI::add_command( 'rss', 'RSSFeed_Command' );
}

endif;
