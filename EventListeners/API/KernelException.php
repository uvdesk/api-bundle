<?php

namespace Webkul\UVDesk\ApiBundle\EventListeners\API;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class KernelException
{
    private $firewall;

    public function __construct(FirewallMap $firewall)
    {
        $this->firewall = $firewall;
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $request = $event->getRequest();
        $exception = $event->getException();

        // Proceed only if we're in the 'uvdesk_api' firewall
        if ('uvdesk_api' != $this->firewall->getFirewallConfig($request)->getName()) {
            return;
        }

        // Handle api exception accordingly
        switch (true) {
            case $exception instanceof \ErrorException:
                $responseContent['status'] = false;
                $responseContent['message'] = 'An unexpected error occurred. Please try again later.';

                $event->setResponse(new JsonResponse($responseContent, Response::HTTP_INTERNAL_SERVER_ERROR));
                break;
            case $exception instanceof AccessDeniedHttpException:
                $responseContent['status'] = false;
                
                if (403 === $exception->getStatusCode()) {
                    $responseContent['message'] = 'You\'re not authorized to perform this action.';
                } else {
                    $responseContent['message'] = $exception->getMessage();
                }

                $event->setResponse(new JsonResponse($responseContent, Response::HTTP_FORBIDDEN));
                break;
            default:
                $responseContent['status'] = false;
                $responseContent['message'] = $exception->getMessage();

                $event->setResponse(new JsonResponse($responseContent, Response::HTTP_INTERNAL_SERVER_ERROR));
                break;
        }
        
        return;
    }
}
