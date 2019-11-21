<?php

namespace Webkul\UVDesk\ApiBundle\UIComponents\Dashboard\Panel\Items\Settings;

use Webkul\UVDesk\CoreFrameworkBundle\Dashboard\Segments\PanelSidebarItemInterface;
use Webkul\UVDesk\CoreFrameworkBundle\UIComponents\Dashboard\Panel\Sidebars\Settings;

class ApiSettings implements PanelSidebarItemInterface
{
    public static function getTitle() : string
    {
        return "API";
    }

    public static function getRouteName() : string
    {
        return 'uvdesk_api_load_configurations';
    }

    public static function getSupportedRoutes() : array
    {
        return [];
    }

    public static function getRoles() : array
    {
        return ['ROLE_ADMIN'];
    }

    public static function getSidebarReferenceId() : string
    {
        return Settings::class;
    }
}
