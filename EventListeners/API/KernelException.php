<?php

namespace Webkul\UVDesk\ApiBundle\EventListeners\API;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class KernelException
{
    private $firewall;

    public function __construct(FirewallMap $firewall)
    {
        $this->firewall = $firewall;
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();

        // Proceed only if we're in the 'uvdesk_api' firewall
        $firewall = $this->firewall->getFirewallConfig($request);

        if (empty($firewall) || 'uvdesk_api' != $firewall->getName()) {
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
