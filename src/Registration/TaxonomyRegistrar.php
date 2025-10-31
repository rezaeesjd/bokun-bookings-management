<?php
namespace Bokun\Bookings\Registration;

if (! defined('ABSPATH')) {
    exit;
}

class TaxonomyRegistrar
{
    /**
     * Register hooks for the plugin taxonomies.
     */
    public function register()
    {
        add_action('init', [$this, 'registerAlarmStatusTaxonomy']);
        add_action('init', [$this, 'registerBookingStatusTaxonomy']);
        add_action('init', [$this, 'registerProductTaxonomy']);
        add_action('init', [$this, 'registerTeamMemberTaxonomy']);
    }

    public function registerAlarmStatusTaxonomy()
    {
        register_taxonomy(
            'alarm_status',
            'bokun_booking',
            [
                'labels'            => [
                    'name'          => __('Alarm Status', BOKUN_TEXT_DOMAIN),
                    'singular_name' => __('Alarm Status', BOKUN_TEXT_DOMAIN),
                ],
                'public'           => true,
                'rewrite'          => ['slug' => 'alarm-status'],
                'hierarchical'     => false,
                'show_in_nav_menus' => true,
                'show_in_menu'     => true,
                'show_in_rest'     => true,
                'show_ui'          => true,
                'show_admin_column' => true,
            ]
        );

        foreach (['Ok', 'Attention', 'Alarm'] as $term) {
            if (! term_exists($term, 'alarm_status')) {
                wp_insert_term($term, 'alarm_status');
            }
        }
    }

    public function registerBookingStatusTaxonomy()
    {
        register_taxonomy(
            'booking_status',
            'bokun_booking',
            [
                'label'        => __('Booking Status', BOKUN_TEXT_DOMAIN),
                'rewrite'      => ['slug' => 'booking-status'],
                'hierarchical' => false,
                'show_in_rest' => true,
            ]
        );
    }

    public function registerProductTaxonomy()
    {
        register_taxonomy(
            'product_tags',
            'bokun_booking',
            [
                'label'        => __('Product Tags', BOKUN_TEXT_DOMAIN),
                'rewrite'      => [
                    'slug'       => 'product-tags',
                    'with_front' => true,
                ],
                'public'       => true,
                'hierarchical' => false,
                'show_ui'      => true,
                'show_in_nav_menus' => true,
                'show_in_rest' => true,
            ]
        );
    }

    public function registerTeamMemberTaxonomy()
    {
        register_taxonomy(
            'team_member',
            'bokun_booking',
            [
                'label'             => __('Team Members', BOKUN_TEXT_DOMAIN),
                'rewrite'           => ['slug' => 'team-member'],
                'public'            => true,
                'hierarchical'      => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_in_rest'      => true,
            ]
        );
    }
}
