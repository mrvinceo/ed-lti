<?php

require_once 'vendor/autoload.php';
require_once 'classes/class-ed-tool-provider.php';
require_once 'classes/class-user-lti-roles.php';
require_once 'classes/class-blog-handler-factory.php';
require_once 'classes/class-blog-handler.php';
require_once 'classes/class-course-blog-handler.php';
require_once 'classes/class-student-blog-handler.php';
require_once 'classes/class-ed-lti-data.php';
require_once 'classes/class-ed-lti-settings.php';

use IMSGlobal\LTI\ToolProvider\DataConnector\DataConnector;
use IMSGlobal\LTI\ToolProvider;

/**
 * Class for coordinating main LTI functions.
 *
 * @author Richard Lawson <richard.lawson@ed.ac.uk>
 */
class Ed_LTI {

	private $wpdb;

	public function __construct() {
		global $wpdb;

		$this->wpdb = $wpdb;

		add_action( 'parse_request', [ $this, 'lti_do_launch' ] );
		add_action( 'parse_request', [ $this, 'lti_add_staff_to_student_blog' ] );

        new Ed_LTI_Settings();
	}

	/**
	 * Activate the plugin
	 *
	 * @return void
	 */
	public static function activate() {
		$lti_data = new Ed_LTI_Data();

		$lti_data->lti_maybe_create_db();
		$lti_data->lti_maybe_create_site_blogs_meta_table();
	}

	// TODO Look at handling blog category.

	/**
	 * Get a DB connector for the LTI connection package
	 *
	 * @return DataConnector
	 */
	private function lti_get_db_connector() {
		// phpcs:disable
		return DataConnector::getDataConnector(
			$this->wpdb->base_prefix,
			new PDO( 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD )
		);
		// phpcs:enable
	}

	/**
	 * TODO: Need to figure out what this function does and write a description
	 *
	 * @return void
	 */
	public function lti_do_launch() {
		if ( $this->lti_is_basic_lti_request() && is_main_site() ) {
			$this->lti_destroy_session();

			$tool = new Ed_Tool_Provider( $this->lti_get_db_connector() );

			$tool->handleRequest();

			if ( ! isset( $_SESSION['lti_okay'] ) ) {
				wp_die( 'There is a problem with your lti connection.', 200 );
			}

			$blog_type = isset( $_REQUEST['custom_blog_type'] ) ? $_REQUEST['custom_blog_type'] : '';

			if ( $this->is_student_blog_and_non_student( $blog_type, $tool ) ) {
				$course_id = $_REQUEST['context_label'];

				// phpcs:disable
				$resource_link_id = $tool->resourceLink->getId();
				// phpcs:enable

				$this->lti_show_staff_student_blogs_for_course( $course_id, $resource_link_id, $tool );

				return;
			}

			$user = $this->first_or_create_user( $this->lti_get_user_data( $tool ) );

			$blog_handler = Blog_Handler_Factory::instance( $blog_type );
			$blog_handler->init( $this->lti_get_site_data(), $user );
			$blog_id = $blog_handler->first_or_create_blog();

			$user_roles = new User_LTI_Roles( $tool->user->roles );
			$blog_handler->add_user_to_blog( $user, $blog_id, $user_roles );

			$this->lti_signin_user( $user, $blog_id );
		}
	}

    /**
     * Check that the LTI request being received is a basic LTI request
     *
     * @return bool
     */
	private function lti_is_basic_lti_request() {
        $good_message_type	= isset( $_REQUEST['lti_message_type'] )
                            ? 'basic-lti-launch-request' == $_REQUEST['lti_message_type']
                            : false;

		$good_lti_version	= isset( $_REQUEST['lti_version'] ) ? 'LTI-1p0' == $_REQUEST['lti_version'] : false;
		$oauth_consumer_key = isset( $_REQUEST['oauth_consumer_key'] );
		$resource_link_id	= isset( $_REQUEST['resource_link_id'] );

		if ( $good_message_type && $good_lti_version && $oauth_consumer_key && $resource_link_id ) {
			return true;
		}

		return false;
	}

    /**
     * Destroy the LTI session
     *
     * @return void
     */
	private function lti_destroy_session() {
		wp_logout();
		wp_set_current_user( 0 );

		if ( session_status() == PHP_SESSION_NONE ) {
			session_start();
		}

		$_SESSION = [];

		session_destroy();
		session_start();
	}

    /**
     * Determine if a non-student user is accessing a student blog
     *
     * @return bool
     */
	private function is_student_blog_and_non_student( $blog_type, Ed_Tool_Provider $tool ) {
		return ( 'student' == $blog_type && ! $tool->user->isLearner() );
	}

    /**
     * Get the user data passed via LTI
     *
     * @return array
     */
	private function lti_get_user_data( Ed_Tool_Provider $tool ) {
        // LTI specs tell us that username should be set in the 'lis_person_sourcedid' param, but moodle doesn't do
        // this. Moodle seems to use 'ext_user_username' instead
        $username = '' != $_REQUEST['lis_person_sourcedid']
                  ? $_REQUEST['lis_person_sourcedid']
                  : $_REQUEST['ext_user_username'];

		$user_data = [
			'username'	=> $username,
			'email'		=> $tool->user->email,
			'firstname' => $tool->user->firstname,
			'lastname'	=> $tool->user->lastname,
			'password'	=> $this->random_string(20, '0123456789ABCDEFGHIJKLMNOPQRSTUVWZYZabcdefghijklmnopqrstuvwxyz'),
		];

		return $user_data;
	}

    /**
     * Get site information for the LTI provider
     *
     * @return array
     */
	private function lti_get_site_data() {
		$site_category = isset( $_REQUEST['custom_site_category'] ) ? $_REQUEST['custom_site_category'] : 1;

        $username = '' != $_REQUEST['lis_person_sourcedid']
                  ? $_REQUEST['lis_person_sourcedid']
                  : $_REQUEST['ext_user_username'];

		return [
			'course_id'		   => $_REQUEST['context_label'],
			'course_title'	   => $_REQUEST['context_title'],
			'domain'		   => get_current_site()->domain,
			'resource_link_id' => $_REQUEST['resource_link_id'],
			'username'		   => $username,
			'site_category'    => $site_category,
			'source_id'		   => get_site_option( 'default_site_template_id' ),
		];
	}

    /**
     * Create a WordPress user or return the logged in user
     *
     * @return WP_User
     */
	private function first_or_create_user( array $data ) {
		$user = get_user_by( 'login', $data['username'] );

		if ( ! $user ) {
			$user_id = wpmu_create_user( $data['username'], $data['password'], $data['email'] );

			if ( ! $user_id ) {
                $error_message = 'This Email address is already being used by another user. Please contact <a href="'
                               . get_site_option( 'is_helpline_url' ) . '">IS Helpline</a> for assistance.';

                wp_die( $error_mesasge, 200 );
			}

			$user = get_userdata( $user_id );

			$user->first_name = $data['firstname'];
			$user->last_name  = $data['lastname'];

			wp_update_user( $user );
		}

		return $user;
	}

    /**
     * Create a login session for a user that has visited the blog via an LTI connection
     *
     * @return void
     */
	private function lti_signin_user( $user, $blog_id ) {
		switch_to_blog( $blog_id );

		clean_user_cache( $user->ID );
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, true );

		update_user_caches( $user );

		if ( is_user_logged_in() ) {
			wp_safe_redirect( home_url() );
			exit;
		}
	}

    /**
     * Create a list of student blogs for a given course for a member of staff
     *
     * @return void
     */
	private function lti_show_staff_student_blogs_for_course( $course_id, $resource_link_id, Ed_Tool_Provider $tool ) {
        $this->lti_add_staff_info_to_session(
            $this->lti_get_user_data( $tool ),
            new User_LTI_Roles( $tool->user->roles ),
            $course_id,
            $resource_link_id
        );

		$this->lti_render_student_blogs_list_view( $course_id, $resource_link_id );
	}

    /**
     * Add staff details to a current LTI session
     *
     * @return void
     */
    private function lti_add_staff_info_to_session(
        array $user_data,
        User_LTI_Roles $user_roles,
        $course_id,
        $resource_link_id
    ) {
		$_SESSION['lti_staff']			  = true;
		$_SESSION['lti_user_roles']		  = $user_roles;
		$_SESSION['lti_staff_user_data']  = $user_data;
		$_SESSION['lti_staff_course_id']  = $course_id;
		$_SESSION['lti_resource_link_id'] = $resource_link_id;
	}

    /**
     * Render a list of student blogs
     *
     * @return void
     */
	private function lti_render_student_blogs_list_view( $course_id, $resource_link_id ) {
		$blog_type = 'student';


        $query = "SELECT * FROM {$this->wpdb->base_prefix}blogs_meta "
               . "INNER JOIN {$this->wpdb->base_prefix}blogs "
               . "ON {$this->wpdb->base_prefix}blogs.blog_id = {$this->wpdb->base_prefix}blogs_meta.blog_id "
               . "WHERE course_id = %s "
               . "AND resource_link_id = %s "
               . "AND blog_type = %s",

		$blogs = $this->wpdb->get_results(
			$this->wpdb->prepare(
                $query,
				$course_id,
				$resource_link_id,
				$blog_type
			)
		);

		get_template_part( 'header' );

	    echo '<div style="width:80%; margin: 0 auto">';

		if ( empty( $blogs ) ) {
			echo '<p>No Student Blogs have been created for this course.</p>';
		} else {
			echo '<h2>Student Blogs For Course</h2>';
			echo '<ul>';

			foreach ( $blogs as $blog ) {
				$blog_details = get_blog_details( $blog->blog_id );

                echo '<li><a href="index.php?lti_staff_view_blog=true&blog_id=' . $blog->blog_id . '"><' .
                     $blog_details->blogname . '</a></li>';
            }
		}

        echo '<br><br>';
        echo '</div>';

		get_template_part( 'footer' );

		exit;
	}

    /**
     * Add staff members to student blogs
     *
     * @return void
     */
	public function lti_add_staff_to_student_blog() {
		if ( isset( $_REQUEST['lti_staff_view_blog'] ) && 'true' == $_REQUEST['lti_staff_view_blog'] ) {
			if ( session_status() == PHP_SESSION_NONE ) {
				session_start();
			}

			if ( ! isset( $_SESSION['lti_staff'] ) ) {
				wp_die( 'You do not have permssion to view this page' );
			}

			$blog_id	= $_REQUEST['blog_id'];
			$course_id	= $_SESSION['lti_staff_course_id'];
			$user_roles = $_SESSION['lti_user_roles'];

            // If someone has been messing about with the blog id and the blog has nothing to do with the current
            // course redirect them to the home page
			if ( ! Blog_Handler::is_course_blog( $course_id, $blog_id ) ) {
				$this->lti_redirect_user_to_blog_without_login( $blog_id );
			}

			$user = $this->first_or_create_user( $_SESSION['lti_staff_user_data'] );

			$blog_handler = Blog_Handler_Factory::instance( 'student' );
			$blog_handler->add_user_to_blog( $user, $blog_id, $user_roles );

			$this->lti_signin_user( $user, $blog_id );
		}
	}

    /**
     * Redirect a user to the defined home URL
     *
     * @return void
     */
	private function lti_redirect_user_to_blog_without_login( $blog_id ) {
		switch_to_blog( $blog_id );
		wp_safe_redirect( home_url() );
        exit;
	}

    /**
     * Generates a cryptographically secure random string of a given length which can be used for generating passwords
     *
     * Adapted from https://paragonie.com/blog/2015/07/how-safely-generate-random-strings-and-integers-in-php
     *
     * @return string
     */
    private function random_string($length, $alphabet) {
        if ($length < 1) {
            throw new InvalidArgumentException('Length must be a positive integer');
        }

        $str = '';

        $alphamax = strlen($alphabet) - 1;

        if ($alphamax < 1) {
            throw new InvalidArgumentException('Invalid alphabet');
        }

        for ($i = 0; $i < $length; ++$i) {
            $str .= $alphabet[random_int(0, $alphamax)];
        }

        return $str;
    }
}
