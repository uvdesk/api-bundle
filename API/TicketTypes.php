<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketType;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

class TicketTypes extends AbstractController
{
    /**
     * Getting listing of all ticket types
     *
     * @param Request $request
     * @return void
    */
    public function ticketTypeList(Request $request) 
    {
        $entityManager = $this->getDoctrine()->getManager();
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select("ticket_type")
            ->from(TicketType::class, 'ticket_type')
            ->where('ticket_type.isActive = 1')
        ;

        $collection = $queryBuilder->getQuery()->getArrayResult();

        return new JsonResponse($collection);
    }
}

