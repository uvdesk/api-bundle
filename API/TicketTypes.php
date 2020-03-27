<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Webkul\TicketBundle\Entity\Ticket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

class TicketTypes extends Controller
{
    /**
     * Getting listing of all ticket types
     *
     * @param Request $request
     * @return void
    */
    public function ticketTypeList(Request $request) 
    {
        $json = [];
        $entityManager = $this->getDoctrine()->getManager();
        $ticketTypes =  $entityManager->createQueryBuilder()
                                      ->select("TT")
                                      ->from('UVDeskCoreFrameworkBundle:TicketType', 'TT')
                                      ->Where('TT.isActive = 1')
                                      ->getQuery()->getArrayResult();

        return new JsonResponse($ticketTypes); 
    }
}

