<?php

namespace Webkul\UVDesk\ApiBundle\EventListeners\API;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class KernelRequest
{
    private $firewall;

    public function __construct(FirewallMap $firewall)
    {
        $this->firewall = $firewall;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        
        $request = $event->getRequest();
        $method  = $request->getRealMethod();

        if ('OPTIONS' == $method) {
            $event->setResponse(new Response());
        }

        return;
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        $request = $event->getRequest();
        
        if (!$event->isMasterRequest()) {
            return;
        }

        if ('OPTIONS' == $request->getRealMethod() || 'POST' == $request->getRealMethod() || 'GET' == $request->getRealMethod()) {
            $response = $event->getResponse();
            
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', ['Access-Control-Allow-Origin', 'Authorization', 'Content-Type']);
        }
        
        return;
    }
}
