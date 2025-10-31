<?php
namespace Bokun\Bookings\Registration;

if (! defined('ABSPATH')) {
    exit;
}

class PostTypeRegistrar
{
    /**
     * Register the WordPress hooks for the booking post type.
     */
    public function register()
    {
        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * Register the custom post type used to store bookings.
     */
    public function registerPostType()
    {
        register_post_type('bokun_booking', [
            'labels' => [
                'name'                  => __('Bokun Bookings'),
                'singular_name'         => __('Bokun Booking'),
                'add_new'               => __('Add New'),
                'add_new_item'          => __('Add New Bokun Booking'),
                'edit_item'             => __('Edit Bokun Booking'),
                'new_item'              => __('New Bokun Booking'),
                'view_item'             => __('View Bokun Booking'),
                'search_items'          => __('Search Bokun Bookings'),
                'not_found'             => __('No Bokun Bookings found'),
                'not_found_in_trash'    => __('No Bokun Bookings found in Trash'),
                'all_items'             => __('All Bokun Bookings'),
                'archives'              => __('Bokun Booking Archives'),
                'insert_into_item'      => __('Insert into Bokun Booking'),
                'uploaded_to_this_item' => __('Uploaded to this Bokun Booking'),
                'featured_image'        => __('Featured Image'),
                'set_featured_image'    => __('Set featured image'),
                'remove_featured_image' => __('Remove featured image'),
                'use_featured_image'    => __('Use as featured image'),
                'menu_name'             => __('Bokun Bookings Management'),
                'filter_items_list'     => __('Filter Bokun Bookings list'),
                'items_list_navigation' => __('Bokun Bookings list navigation'),
                'items_list'            => __('Bokun Bookings list'),
            ],
            'public'            => true,
            'has_archive'       => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'supports'          => ['title', 'editor', 'custom-fields', 'author', 'comments', 'revisions', 'thumbnail', 'excerpt', 'page-attributes'],
            'rewrite'           => ['slug' => 'bokun-booking'],
            'taxonomies'        => ['alarm_status', 'team_member'],
            'capability_type'   => 'post',
            'map_meta_cap'      => true,
            'show_ui'           => true,
            'show_in_nav_menus' => true,
            'menu_icon'         => 'dashicons-calendar',
        ]);
    }
}
