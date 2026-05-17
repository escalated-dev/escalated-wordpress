<?php

/**
 * API Bootstrap - registers all REST API routes.
 */

namespace Escalated\Api;

class Api_Bootstrap
{
    /**
     * Hook into WordPress to register routes on rest_api_init.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Instantiate all controllers and register their routes.
     */
    public function register_routes(): void
    {
        $controllers = [
            new Auth_Controller,
            new Ticket_Controller,
            new Department_Controller,
            new Tag_Controller,
            new Canned_Response_Controller,
            new Macro_Controller,
            new Agent_Controller,
            new Automation_Controller,
            new Dashboard_Controller,
            new Api_Token_Controller,
            new Events_Controller,
            new Widget_Controller,
            new Saved_View_Controller,
            new Ticket_Snooze_Controller,
            new Ticket_Split_Controller,
            new Chat_Controller,
            new Widget_Chat_Controller,
            new Skill_Controller,
        ];

        foreach ($controllers as $controller) {
            $controller->register_routes();
        }
    }
}
