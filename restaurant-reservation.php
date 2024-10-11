<?php
/**
 * Plugin Name: Restaurant Reservation
 * Description: Plugin for a restaurant reservation system.
 * Version: 1.0
 * Author: Thomas
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Register Custom Post Type for Tables
function register_table_cpt() {
  $labels = array(
      'name' => __( 'Tables' ),
      'singular_name' => __( 'Table' ),
      'menu_name' => __( 'Tables' ),
      'all_items' => __( 'All Tables' ),
  );

  $args = array(
      'label'               => __( 'Tables' ),
      'public'              => true,
      'show_ui'             => true,
      'show_in_rest'        => true,
      'supports'            => array( 'title' ),
      'has_archive'         => false,
  );

  register_post_type( 'table', $args );
}
add_action( 'init', 'register_table_cpt' );

// Register Custom Post Type for Reservations
function create_reservation_cpt() {
  $labels = array(
      'name' => __('Reservations', 'textdomain'),
      'singular_name' => __('Reservation', 'textdomain'),
      'menu_name' => __('Reservations', 'textdomain'),
      'name_admin_bar' => __('Reservation', 'textdomain'),
      'add_new' => __('Add New', 'textdomain'),
      'add_new_item' => __('Add New Reservation', 'textdomain'),
      'new_item' => __('New Reservation', 'textdomain'),
      'edit_item' => __('Edit Reservation', 'textdomain'),
      'view_item' => __('View Reservation', 'textdomain'),
      'all_items' => __('All Reservations', 'textdomain'),
      'search_items' => __('Search Reservations', 'textdomain'),
      'not_found' => __('No reservations found.', 'textdomain'),
      'not_found_in_trash' => __('No reservations found in Trash.', 'textdomain'),
  );

  $args = array(
      'labels' => $labels,
      'public' => true,
      'has_archive' => true,
      'supports' => array('title', 'editor', 'custom-fields'), // Adjust supports as needed
      'rewrite' => array('slug' => 'reservations'),
  );

  register_post_type('reservation', $args);
}
add_action('init', 'create_reservation_cpt');


// Make ACF fields available in REST API for the Table CPT
add_action( 'rest_api_init', function() {
  register_rest_field( 'table', 'status', array(
    'get_callback' => function( $post ) {
      return get_field( 'status', $post['id'] ); // 'status' is the ACF field name
    },
    'schema' => null,
  ));

  register_rest_field( 'table', 'capacity', array(
      'get_callback' => function( $post ) {
          return get_field( 'capacity', $post['id'] ); // 'capacity' is the ACF field name
      },
      'schema' => null,
  ));
});

// Register route to list tables
add_action( 'rest_api_init', function() {
  register_rest_route( 'restaurant/v1', '/tables/', array(
    'methods'  => 'GET',
    'callback' => 'get_available_tables',
  ));
});

// Callback function that returns available tables
function get_available_tables( $data ) {
  $args = array(
    'post_type' => 'table',
    'posts_per_page' => -1,
  );
  $tables = get_posts( $args );

  // Transform the result into a format that the frontend can use
  $response = array();
  foreach( $tables as $table ) {
    $response[] = array(
      'id' => $table->ID,
      'name' => $table->post_title,
      'status' => get_field('status', $table->ID),
      'capacity' => get_field('capacity', $table->ID),
    );
  }

  return new WP_REST_Response( $response, 200 );
}

// Register route to make a reservation
function register_table_reservation_routes() {
  register_rest_route( 'restaurant/v1', '/reserve/', array(
      'methods' => 'POST',
      'callback' => 'handle_table_reservation',
      'permission_callback' => '__return_true',
  ));
}
add_action( 'rest_api_init', 'register_table_reservation_routes' );

function handle_table_reservation( WP_REST_Request $request ) {
  $table_id = $request['table_id'];
  $customer_name = sanitize_text_field( $request['customer_name'] );
  $num_people = intval( $request['num_people'] );

  // Basic validation
  if ( empty( $table_id ) || empty( $customer_name ) || empty( $num_people ) ) {
    return new WP_Error( 'invalid_data', 'Please provide valid data.', array( 'status' => 400 ) );
  }

  // Set the status of the table to 'Occupied'
  update_field( 'status', 'Occupied', $table_id );

  // Create a new reservation post in the custom post type
  $reservation_post = array(
    'post_title'   => $customer_name,
    'post_content' => '',
    'post_status'  => 'publish',
    'post_type'    => 'reservation',
    'meta_input'   => array(
      'table_id'       => $table_id,
      'customer_name'  => $customer_name,
      'num_people'     => $num_people,
    ),
  );
  // Insert the reservation post into the database
  $reservation_id = wp_insert_post($reservation_post);

  if ($reservation_id) {
    update_field('table_id', $table_id, $reservation_id);
    update_field('number_of_peoples', $num_people, $reservation_id);
  }

  if (is_wp_error($reservation_id)) {
    return new WP_Error('reservation_error', 'Failed to create reservation.', array('status' => 500));
  }

  return new WP_REST_Response(array('message' => 'Table reserved successfully!'), 200);
}

// Register route to list tables
add_action( 'rest_api_init', function() {
  register_rest_route( 'restaurant/v1', '/reservations/', array(
    'methods'  => 'GET',
    'callback' => 'get_reservations',
  ));
});

// Callback function that returns reservations
function get_reservations( $data ) {
  $args = array(
    'post_type' => 'reservation',
    'posts_per_page' => -1,
  );
  $reservations = get_posts( $args );

  // Transform the result into a format that the frontend can use
  $response = array();
  foreach( $reservations as $reservation ) {
    $response[] = array(
      'id' => $reservation->ID,
      'customer_name' => $reservation->post_title,
      'table_id' => get_field('table_id', $reservation->ID),
      'number_of_peoples' => get_field('number_of_peoples', $reservation->ID),
    );
  }

  return new WP_REST_Response( $response, 200 );
}

// Callback function to delete reservation
add_action('rest_api_init', function () {
  register_rest_route('restaurant/v1', '/reservation/(?P<id>\d+)', array(
      'methods' => 'DELETE',
      'callback' => 'delete_reservation',
      'permission_callback' => '__return_true',
  ));
});

function delete_reservation( $data ) {
  $reservation_id = $data['id'];

  if( wp_delete_post($reservation_id) ) {
    return new WP_REST_Response('Reservation deleted successfully', 200); 
  } else {
    return new WP_REST_Response('Error deleting the reservation', 500);
  }
}