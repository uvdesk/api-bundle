<?php

namespace Webkul\UVDesk\ApiBundle\UIComponents\Dashboard\Search;

use Webkul\UVDesk\CoreFrameworkBundle\Dashboard\Segments\SearchItemInterface;

class ApiSettings implements SearchItemInterface
{
    CONST SVG = <<<SVG
<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="#000000"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"><path d="M21.3,19a2.42,2.42,0,0,1-2.5.56l-2.35,2.35a.34.34,0,0,1-.49,0l-1-1a.36.36,0,0,1,0-.49l2.36-2.35a2.39,2.39,0,0,1,3.39-2.91L19.12,16.8l1,1,1.62-1.62A2.39,2.39,0,0,1,21.3,19ZM22,8v5.76A4.47,4.47,0,0,0,19.5,13a4.57,4.57,0,0,0-1.29.19V9.29H16.66V14A4.5,4.5,0,0,0,15,17.5a4.07,4.07,0,0,0,0,.5H4a2,2,0,0,1-2-2V8A2,2,0,0,1,4,6H20A2,2,0,0,1,22,8ZM11,15,9.09,9.27H7L5.17,15h1.7l.29-1.07H9L9.29,15Zm4.77-3.89a1.67,1.67,0,0,0-.55-1.35,2.43,2.43,0,0,0-1.62-.47h-2V15h1.54V13.11h.44a2.75,2.75,0,0,0,1-.17,1.82,1.82,0,0,0,.67-.44,1.63,1.63,0,0,0,.36-.64A2.36,2.36,0,0,0,15.75,11.11Zm-7.3.62-.12-.44-.15-.58c0-.21-.08-.37-.11-.5a4.63,4.63,0,0,1-.1.48c0,.19-.08.38-.13.57s-.08.34-.12.47l-.24.93H8.69Zm5.59-1a.63.63,0,0,0-.5-.17h-.4v1.31h.31a.9.9,0,0,0,.37-.07.59.59,0,0,0,.27-.22.75.75,0,0,0,.11-.42A.57.57,0,0,0,14,10.71Z"></path><rect width="24" height="24" fill="none"></rect></g></svg>
SVG;

    public static function getIcon() : string
    {
        return self::SVG;
    }

    public static function getTitle() : string
    {
        return "API";
    }

    public static function getRouteName() : string
    {
        return 'uvdesk_api_load_configurations';
    }

    public static function getRoles() : array
    {
        return [];
    }

    public function getChildrenRoutes() : array
    {
        return [];
    }
}
