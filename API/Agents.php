<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;

class Agents extends AbstractController
{
    public function loadAgents(Request $request, ContainerInterface $container)
    {
        $collection = [];

        return new JsonResponse([
            'success' => true, 
            'collection' => $collection, 
        ]);
    }

    public function loadAgentDetails($id, Request $request, ContainerInterface $container)
    {
        return new JsonResponse([
            'success' => true, 
            'agent' => [
                'id' => $id, 
                // ...
            ], 
        ]);
    }
}
