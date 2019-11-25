<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Ticket;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
    public function trashTickets(Request $request, EventDispatcherInterface $eventDispatcher)
    {   
        $json = [];
        $user = $this->getUser();
        $json['failedCount']  = 0;
        $json['succeedCount'] = 0;
        $entityManager = $this->getDoctrine()->getManager();
        $userService = $this->container->get('user.service');
        $jsonData = json_decode($request->getContent(), true);
        $ticketRepository = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket');
        
        if (empty($jsonData['ticketIds'])) {
            return new JsonResponse($json, Respons::HTTP_BAD_REQUEST);
        }
        $ticketIds = explode(',', $jsonData['ticketIds']);        
        
        $trash_ticket = function (Ticket $ticket) use ($entityManager, $eventDispatcher) {
            if (!$ticket->getIsTrashed()) {
                $ticket->setIsTrashed(1);
                $entityManager->persist($ticket);
                $entityManager->flush();
            }
            $event = new GenericEvent(CoreWorkflowEvents\Ticket\Delete::getId(), [
                'entity' => $ticket,
            ]);
            $eventDispatcher->dispatch($event);
        };

        if (empty($ticketIds)) {
            return new JsonRespons($json, Response::HTTP_OK); 
        }
        
        $agentInstance = $user->getAgentInstance();
        switch($agentInstance->getSupportRole()->getCode()) {
            case 'ROLE_AGENT':
                if (!$userService->isAuthorized('ROLE_AGENT_DELETE_TICKET', $user)) {
                    return new JsonResponse($json, Response::HTTP_FORBIDDEN);
                }
                switch ($agentInstance->getTicketAccessLevel()) {
                    case TICKET_GLOBAL_ACCESS:
                        foreach ($ticketIds as $index => $ticketId) {
                            $ticket = $ticketRepository->findOneById($ticketId);

                            if (!$ticket) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Ticket not round!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }
                            $trash_ticket($ticket);
                            $json['succeedCount']++;
                        }
                        break;   
                    case TICKET_GROUP_ACCESS:
                        foreach ($ticketIds as $index => $ticketId) {
                            $isPermitted = false; 
                            $ticket = $ticketRepository->find($ticketId);
                            if (!$ticket) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Ticket not round!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }

                            $ticketSupportGroup = $ticket->getSupportGroup();
                            if (!empty($ticketSupportGroup)) {
                                $agentSupportGroups = $agentInstance->getSupportGroups();
                                if (!empty($agentSupportGroups)) {
                                    foreach($agentSupportGroups as $agentSupportGroup) {
                                        if (!empty($agentSupportGroup) && $agentSupportGroup->getId() == $ticketGroup->getId()) {
                                            $isPermitted = true;
                                            break;
                                        }
                                    }
                                }
                                $agentSupportTeams = $agentInstance->getSupportTeams();
                                if (!$isPermitted && !empty($agentSupportTeams)) {
                                    foreach($agentSupportTeams as $agentSupportTeam) {
                                        if (empty($agentSupportTeam)) {
                                            continue;
                                        }
                                        $agentSupportTeamGroups = $agentSupportTeam->getSuportGroups();
                                        if (empty($agnetSupportTeamGroups)) {
                                            continue;
                                        }
                                        foreach ($agentSupportTeamGroups as  $agentSupportTeamGroup) {
                                            if (!empty(!$agentSupportTeamGroup) && $agentSupportTeamGroup->getId() == $ticketSupportGroup->getId()) {
                                                $isPermitted = true;
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }

                            if (!$isPermitted) {
                                if ($ticket->getAgent()->getId() == $user->getId()) {
                                    $isPermitted = true;
                                }
                            }
                            
                            if (!$isPermitted) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Permission Denied!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }
                            $trash_ticket($ticket);
                            $json['succeedCount']++;
                        }

                        break;   
                    case TICKET_TEAM_ACCESS:
                        foreach ($ticketIds as $index => $ticketId) {
                            $isPermitted = false; 
                            $ticket = $ticketRepository->findOneById($ticketId);
                            if (!$ticket) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Ticket not round!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }

                            $ticketSupportTeam  = $ticket->getSupportTeam();
                            if (!empty($ticketSupportTeam)) {
                                $agentSupportTeams  = $agent->getSupportTeams();
                                if (!empty($agentSupportTeams)) {
                                    foreach ($agentSupportTeams as $agentSupportTeam) {
                                        if( !empty($agentSupportTeam) && $ticketSupportTeam->getId() == $agentSupportTeam->getId()) {
                                            $isPermitted = true;
                                            
                                            break;
                                        }
                                    }
                                }
                                $agentSupportGroups = $agent->getSupportGroups();
                                
                                if (!$isPermitted && !empty($agentSupportGroups)) {
                                    foreach ($agentSupportGroups as $agentSupportGroup) {
                                        $agentSupportGroupTeams = $agentSupportGroup->getSupportTeams();
                                        if (empty($agentSupportGroupTeams)) {
                                            continue;
                                        }
                                        foreach ($agentSupportGroupTeams as $agentSupportGroupTeam) {
                                            if (!empty($agentSupportGroupTeam) && $agentSupportGroupTeam->getId() == $ticketSupportTeam->getId()) {
                                                $isPermitted = true;

                                                break 2;
                                            }
                                        }
                                    }
                                }   
                            }
                            if (!$isPermitted) {
                                if ($ticket->getAgent()->getId() == $user->getId()) {
                                    $isPermitted = true;
                                }
                            }
                            
                            if (!$isPermitted) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Insufficient Permission!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }
                            $trash_ticket($ticket);
                            $json['succeedCount']++;
                        }

                        break;
                    default:
                        foreach($ticketIds as $index => $ticketId) {
                            $ticket = $ticketSupportGroup->find($ticketId);
                            
                            if (!$ticket) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Ticket not round!';
                                $json['failedCount']++;
                                
                                continue;                      
                            }

                            if ($ticket->getAgent()->getId() != $user->getId()) {
                                $json['failedTickets'][$index]['ticketId'] = $ticketId;
                                $json['failedTickets'][$index]['message'] = 'Insufficient Permission!';
                                $json['failedCount']++;
                                
                                continue;                
                            }
                            $trash_ticket($ticket);
                            $json['succeedCount']++;
                        }
                        break;
                }
                break;
            case 'ROLE_ADMIN':
            case 'ROLE_SUPER_ADMIN':
                foreach ($ticketIds as $index => $ticketId) {
                    $ticket = $ticketRepository->find($ticketId);    
                    if (!$ticket) {
                        $json['failedTickets'][$index]['ticketId'] = $ticketId;
                        $json['failedTickets'][$index]['message'] = 'Ticket not round';
                        $json['failedCount']++;
                        
                        continue;                      
                    }
                    $trash_ticket($ticket);
                    $json['succeedCount']++;
                }
                break;
            default:
                return new JsonResponse($json, Reponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse($json, Response::HTTP_OK);
    }
    
    /**
     * Create a new support ticket.
     *
     * @param Request $request
     * @return void
     */
    public function createTicket(Request $request)
    {
        return new JsonResponse([]);
    }
    
    /**
     * Update an existing support ticket.
     *
     * @param Request $request
     * @return void
     */
    public function updateTicket(Request $request)
    {
        return new JsonResponse([]);
    }
}
