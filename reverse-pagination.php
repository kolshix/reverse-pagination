<?php
/*
Plugin Name: WP Reverse Pagination
Description: Pagination starts higher and goes lower for more consistent archive page numbering.
Author: Russell Heimlich
Version: 0.1
*/

class WP_Reverse_Pagination {

	private $new_paged = 0;

	public function _construct() {}

	/**
	 * Get an instance of this class
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static();
			$instance->setup_actions();
			$instance->setup_filters();
		}
		return $instance;
	}

	public function setup_actions() {
		add_action( 'pre_get_posts', array( $this, 'action_pre_get_posts' ) );
		add_action( 'wp_head', array( $this, 'action_wp_head' ), 1 );
	}

	public function setup_filters() {
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_filter( 'get_pagenum_link', array( $this, 'filter_get_pagenum_link' ) );
		add_filter( 'posts_request', array( $this, 'filter_posts_request' ), 10 );
	}

	// This will help in figuring out how to make this work https://ozthegreat.io/wordpress/wordpress-database-queries-speed-sql_calc_found_rows
	public function action_pre_get_posts( $query ) {
		global $wpdb;
		if ( is_admin() || ! $query->is_main_query() ) {
			return $query;
		}

		$q = $query->query_vars;
		$query->set( 'no_found_rows', 1 );

		if ( empty( $q['order'] ) || strtoupper( $q['order'] ) != 'DESC' ) {
			$q['order'] = 'ASC';
		}
		if ( ! $q['paged'] ) {
			// $q['order'] = 'DESC';
		}

		// $wp_query->set( 'order', $q['order'] );
	}

	public function action_wp_head() {
		if ( is_main_query() ) {
			set_query_var( 'paged', $this->new_paged );
		}
	}

	public function filter_redirect_canonical( $redirect_url, $requested_url ) {
		global $paged;
		if ( is_admin() ) {
			return;
		}

		$paged = $this->new_paged;

		if ( stristr( $requested_url, '/page/1/' ) ) {
			return $requested_url;
		}

		return $redirect_url;
	}

	public function filter_get_pagenum_link( $result ) {
		global $paged, $wp_query, $wp_rewrite;

		$old_paged = $wp_query->max_num_pages - $paged;
		if ( $old_paged == 1 ) {
			// $paged = 1;
		}

		$old_next_page = $paged + 1;
		// It's a next page...
		if ( stripos( $result, $wp_rewrite->pagination_base . '/' . $old_next_page ) || $paged == 1 ) {
			$new_next_page = $paged - 1;
			if ( $paged == 1 ) {
				$new_next_page = $wp_query->max_num_pages - 1;
			}

			if ( $old_paged == 1 ) {
				$new_next_page = 1;
			}

			return str_replace( $wp_rewrite->pagination_base . '/' . $old_next_page, $wp_rewrite->pagination_base . '/' . $new_next_page , $result);
		}

		$old_prev_page = $paged - 1;
		if ( $old_prev_page == 0 ) {
			return $result;
		}
		if ( $old_prev_page == 1 ) {
			return $result . $wp_rewrite->pagination_base . '/' . ($paged + 1) . '/';
		}

		// It's a previous page...
		if ( stripos( $result, $wp_rewrite->pagination_base . '/' . $old_prev_page ) ) {
			$new_prev_page = $paged + 1;
			if ( $new_prev_page == $wp_query->max_num_pages ) {
				$new_prev_page = 2;
			}
			return str_replace( $wp_rewrite->pagination_base . '/' . $old_prev_page, $wp_rewrite->pagination_base . '/' . $new_prev_page , $result );
		}

		return $result;
	}

	public function filter_posts_request( $sql ) {
		global $wp_query, $wpdb;

		$original_sql = $sql;
		$q = $wp_query->query_vars;

		if ( ! $wp_query->is_main_query() ) {
			return $sql;
		}

		$pieces = explode( 'FROM', $sql );
		$max_pages_sql = 'SELECT COUNT(*) FROM' . $pieces[1];
		$pieces = explode( ' ORDER ', $max_pages_sql );
		$max_pages_sql = trim( $pieces[0] );

		$found_posts = $wpdb->get_var( $max_pages_sql );
		$wp_query->found_posts = intval( $found_posts );
		$wp_query->found_posts = apply_filters_ref_array( 'found_posts', array( $wp_query->found_posts, &$wp_query ) );

		$posts_per_page = intval( $wp_query->query_vars['posts_per_page'] );
		if ( ! $posts_per_page ) {
			$posts_per_page = get_option( 'posts_per_page' );
		}
		$wp_query->max_num_pages = ceil( $wp_query->found_posts / $posts_per_page );

		$paged = $q['paged'];
		$new_paged = intval( $wp_query->max_num_pages - $paged );
		$new_offset = ( $wp_query->max_num_pages - $paged ) * $posts_per_page;

		$this->new_paged = $new_paged;
		$wp_query->query['paged'] = $new_paged;
		// $wp_query->query_vars['paged'] = $new_paged;

		$old_offset = ( $paged * $posts_per_page) - $posts_per_page;

		$find = 'LIMIT ' . $old_offset . ', ' . $posts_per_page;
		$replace =  'LIMIT ' . $new_offset . ', ' . $posts_per_page;
		$sql = str_replace( $find, $replace, $sql );
		/*
		echo '<pre>';
		var_dump( $paged, $new_paged );
		// var_dump( $find, $replace );
		// var_dump( $sql, $original_sql );
		echo '</pre>';
		*/

		return $sql;
	}

}

// Kick things off!
WP_Reverse_Pagination::get_instance();
