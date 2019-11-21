<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Webkul\TicketBundle\Entity\Ticket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

class Tickets extends Controller
{
    /**
     * Return support tickets.
     *
     * @param Request $request
     */
    public function fetchTickets(Request $request)
    {
        $json = [];
        $ticketRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket');
        $userRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:User');

        $em = $this->getDoctrine()->getManager();

        if($request->query->get('actAsType')) {    
            switch($request->query->get('actAsType')) {
                case 'customer': 
                    $user = $this->getUser();
                    if($user) {
                        $json = $repository->getAllCustomerTickets($request->query, $this->container, $user);
                    } else {
                        $json['error'] = 'Error! Resource not found.';
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    return new JsonResponse($json);
                    break;
                case 'agent':
                    $user = $this->getUser();
                    if($user) {
                        $request->query->set('agent', $user->getId());
                    } else {
                        $json['error'] = 'Error! Resource not found.';
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    break;
                default:
                    $json['error'] = 'Error! invalid actAs details.';
                    return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
                    break;
            }
        }

        $json = $ticketRepository->getAllTickets($request->query, $this->container);

        $json['userDetails'] = [
            'user' => $this->getUser()->getId(),
            'name' => $this->getUser()->getFirstName().' '.$this->getUser()->getLastname(),
        ];
        $json['agents'] = $this->get('user.service')->getAgentsPartialDetails();
        $json['status'] = $this->get('ticket.service')->getStatus();
        $json['group'] = $userRepository->getSupportGroups(); 
        $json['team'] =  $userRepository->getSupportTeams();
        $json['priority'] = $this->get('ticket.service')->getPriorities();
        $json['type'] = $this->get('ticket.service')->getTypes();
        $json['source'] = $this->get('ticket.service')->getAllSources();

        return new JsonResponse($json);
    }

    /**
     * Return support tickets metadata.
     *
     * @param Request $request
     */
    public function fetchTicketsMetadata(Request $request) 
    {
        return new JsonResponse([]);
    }

    /**
     * Trash support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function trashTickets(Request $request)
    {
        return new JsonResponse([]);
    }
}
