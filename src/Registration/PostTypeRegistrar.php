<?php

namespace Bokun\Bookings\Registration;

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
                'name'                  => __('Bokun Bookings', BOKUN_TEXT_DOMAIN),
                'singular_name'         => __('Bokun Booking', BOKUN_TEXT_DOMAIN),
                'add_new'               => __('Add New', BOKUN_TEXT_DOMAIN),
                'add_new_item'          => __('Add New Bokun Booking', BOKUN_TEXT_DOMAIN),
                'edit_item'             => __('Edit Bokun Booking', BOKUN_TEXT_DOMAIN),
                'new_item'              => __('New Bokun Booking', BOKUN_TEXT_DOMAIN),
                'view_item'             => __('View Bokun Booking', BOKUN_TEXT_DOMAIN),
                'search_items'          => __('Search Bokun Bookings', BOKUN_TEXT_DOMAIN),
                'not_found'             => __('No Bokun Bookings found', BOKUN_TEXT_DOMAIN),
                'not_found_in_trash'    => __('No Bokun Bookings found in Trash', BOKUN_TEXT_DOMAIN),
                'all_items'             => __('All Bokun Bookings', BOKUN_TEXT_DOMAIN),
                'archives'              => __('Bokun Booking Archives', BOKUN_TEXT_DOMAIN),
                'insert_into_item'      => __('Insert into Bokun Booking', BOKUN_TEXT_DOMAIN),
                'uploaded_to_this_item' => __('Uploaded to this Bokun Booking', BOKUN_TEXT_DOMAIN),
                'featured_image'        => __('Featured Image', BOKUN_TEXT_DOMAIN),
                'set_featured_image'    => __('Set featured image', BOKUN_TEXT_DOMAIN),
                'remove_featured_image' => __('Remove featured image', BOKUN_TEXT_DOMAIN),
                'use_featured_image'    => __('Use as featured image', BOKUN_TEXT_DOMAIN),
                'menu_name'             => __('Bokun Bookings Management', BOKUN_TEXT_DOMAIN),
                'filter_items_list'     => __('Filter Bokun Bookings list', BOKUN_TEXT_DOMAIN),
                'items_list_navigation' => __('Bokun Bookings list navigation', BOKUN_TEXT_DOMAIN),
                'items_list'            => __('Bokun Bookings list', BOKUN_TEXT_DOMAIN),
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
