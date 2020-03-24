<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Webkul\TicketBundle\Entity\Ticket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

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
        $entityManager = $this->getDoctrine()->getManager();
        $ticketRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket');
        $userRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:User');

        if ($request->query->get('actAsType')) {    
            switch($request->query->get('actAsType')) {
                case 'customer': 
                    $customer = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);

                    if ($customer) {
                        $json = $repository->getAllCustomerTickets($request->query, $this->container, $customer);
                    } else {
                        $json['error'] = 'Error! Resource not found.';
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    return new JsonResponse($json);
                    break;
                case 'agent':
                    $user = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);

                    if ($user) {
                        $request->query->set('agent', $user->getId());
                    } else {
                        $json['error'] = 'Error! Resource not found.';
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    break;
                default:
                    $json['error'] = 'Error! invalid actAs details.';
                    return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
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
    public function trashTicket(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($ticketId);
        
        if (!$ticket) {
            $this->noResultFound();
        }

        if (!$ticket->getIsTrashed()) {
            $ticket->setIsTrashed(1);
            $entityManager->persist($ticket);
            $entityManager->flush();

            $json['success'] = 'Success ! Ticket moved to trash successfully.';
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new GenericEvent(CoreWorkflowEvents\Ticket\Delete::getId(), [
                'entity' => $ticket,
            ]);

            $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
        } else {
            $json['error'] = 'Warning ! Ticket is already in trash.';
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * Create support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function createTicket(Request $request)
    {
        $data = $request->request->all()? : json_decode($request->getContent(),true);
        foreach($data as $key => $value) {
            if(!in_array($key, ['subject', 'group', 'type', 'status','locale','domain', 'priority', 'agent', 'replies', 'createdAt', 'updatedAt', 'customFields', 'files', 'from', 'name', 'message', 'tags', 'actAsType', 'actAsEmail'])) {
                unset($data[$key]);
            }
        }
  
        if(!(isset($data['from']) && isset($data['name']) && isset($data['subject']) && isset($data['message']) &&  isset($data['actAsType']) || isset($data['actAsEmail']) )) {
            $json['error'] = 'required fields: name ,from, message, actAsType or actAsEmail';
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if($data) {
            $error = false;
            $message = '';
            $entityManager = $this->getDoctrine()->getManager();

            if ($data['subject'] == '') {
                $message = "Warning! Please complete subject field value!";
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif($data['message'] == '') {
                $json['message'] = "Warning! Please complete message field value!";
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif(filter_var($data['from'], FILTER_VALIDATE_EMAIL) === false) {
                $json['message'] = "Warning! Invalid from Email Address!";
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
            elseif ($data['actAsType'] == ''  &&  $data['actAsEmail'] == '') {
                $json['message'] = "Warning! Provide atleast one parameter actAsType(agent or customer) or actAsEmail";
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
            
            if (!$error) {
                $name = explode(' ',$data['name']);
                $ticketData['firstName'] = $name[0];
                $ticketData['lastName'] = isset($name[1]) ? $name[1] : '';
                $ticketData['role'] = 4;
             
                if ((array_key_exists('actAsType', $data)) && strtolower($data['actAsType']) == 'customer') {
                    $actAsType = strtolower($data['actAsType']);             
                } else if((array_key_exists('actAsEmail', $data)) && strtolower($data['actAsType']) == 'agent') {
                    $user = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);
                    
                    if ($user) {
                        $actAsType = 'agent';
                    } else {
                        $json['error'] = "Error ! actAsEmail is not valid";
                        return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
                    }
                } else {
                    $json['warning'] = 'Warning ! For Customer spacify actAsType as customer and for Agent spacify both parameter actASType  as agent and actAsEmail as agent email';
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }
                
                // Create customer if account does not exists
                $customer = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['from']);
             
                if (empty($customer) || null == $customer->getCustomerInstance()) {
                    $role = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode('ROLE_CUSTOMER');
                  
                    // Create User Instance
                    $customer = $this->get('user.service')->createUserInstance($data['from'], $data['name'], $role, [
                        'source' => 'api',
                        'active' => true
                    ]);
                }

                if ($actAsType == 'agent') {
                    $data['user'] = isset($user) && $user ? $user : $this->get('user.service')->getCurrentUser();
                } else {
                    $data['user'] = $customer;
                }
                
                $ticketData['user'] = $data['user'];
                $ticketData['subject'] = $data['subject'];
                $ticketData['message'] = $data['message'];
                $ticketData['customer'] = $customer;
                $ticketData['source'] = 'api';
                $ticketData['threadType'] = 'create';
                $ticketData['createdBy'] = $actAsType;
                $ticketData['attachments'] = $request->files->get('attachments');
                
                $extraKeys = ['tags', 'group', 'priority', 'status', 'agent', 'createdAt', 'updatedAt'];
                
                $requestData = $data;
                foreach ($extraKeys as $key) {
                    if (isset($ticketData[$key])) {
                        unset($ticketData[$key]);
                    }
                }
                
                $thread = $this->get('ticket.service')->createTicketBase($ticketData);
                // Trigger ticket created event
                try {
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Create::getId(), [
                        'entity' =>  $thread->getTicket(),
                    ]);
                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
                } catch (\Exception $e) {
                    //
                }

                $json['message'] = 'Success ! Ticket has been created successfully.';
                $json['ticketId'] = $thread->getTicket()->getId();
                $statusCode = Response::HTTP_OK;

            } else {
                $json['message'] = 'Warning ! Required parameters should not be blank';
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        } else {
            $json['error'] = 'invalid/empty size of Request';
            $json['message'] = 'Warning ! Post size can not exceed 25MB';
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * View support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function viewTicket($ticketId, Request $request)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $userRepository = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User');
        $ticketRepository = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket');

        $ticket = $ticketRepository->findOneById($ticketId);

        if (empty($ticket)) {
            throw new \Exception('Page not found');
        }

        $agent = $ticket->getAgent();
        $customer = $ticket->getCustomer();

        // Mark as viewed by agents
        if (false == $ticket->getIsAgentViewed()) {
            $ticket->setIsAgentViewed(true);

            $entityManager->persist($ticket);
            $entityManager->flush();
        }

        // Ticket status Collection
        $status = array_map(function ($statusCollection) {
            return [
                'id' => $statusCollection->getId(),
                'code' => $statusCollection->getCode(),
                'colorCode' => $statusCollection->getColorCode(),
                'description' => $statusCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketStatus')->findAll());

        // Ticket Type Collection
        $type = array_map(function ($ticketTypeCollection) {
            return [
                'id' => $ticketTypeCollection->getId(),
                'code' => $ticketTypeCollection->getCode(),
                'isActive' => $ticketTypeCollection->getIsActive(),
                'description' => $ticketTypeCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketType')->findByIsActive(true));

        // Priority Collection
        $priority = array_map(function ($ticketPriorityCollection) {
            return [
                'id' => $ticketPriorityCollection->getId(),
                'code' => $ticketPriorityCollection->getCode(),
                'colorCode' => $ticketPriorityCollection->getColorCode(),
                'description' => $ticketPriorityCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketPriority')->findAll());
      
        $ticketObj = $ticket;
        $ticket = json_decode($this->objectSerializer($ticketObj), true);
      
        return new JsonResponse([
            'ticket' => $ticket,
            'totalCustomerTickets' => ($ticketRepository->countCustomerTotalTickets($customer)),
            'ticketAgent' => !empty($agent) ? $agent->getAgentInstance()->getPartialDetails() : null,
            'customer' => $customer->getCustomerInstance()->getPartialDetails(),
            'supportGroupCollection' => $userRepository->getSupportGroups(),
            'supportTeamCollection' => $userRepository->getSupportTeams(),
            'ticketStatusCollection' => $status,
            'ticketPriorityCollection' => $priority,
            'ticketTypeCollection' => $type
        ]);
    }

    /**
     * delete support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function deleteTicketForever(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($ticketId);
        
        if (!$ticket) {
            $this->noResultFound();
        }

        if ($ticket->getIsTrashed()) {
            $entityManager->remove($ticket);
            $entityManager->flush();

            $json['success'] = 'Success ! Ticket removed successfully.';
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new GenericEvent(CoreWorkflowEvents\Ticket\Delete::getId(), [
                'entity' => $ticket,
            ]);

            $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
        } else {
            $json['error'] = 'Warning ! something went wrong.';
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * Assign Ticket to a agent
     *
     * @param Request $request
     * @return void
    */

    public function assignAgent(Request $request)
    {
        $json = [];
        $data = json_decode($request->getContent(), true);
        $userId = $request->attributes->get('id');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('WebkulTicketBundle:Ticket')->findOneBy(array('id' => $userId));

        if ($ticket) {
            if (isset($data['id'])) {
                $agent = $entityManager->getRepository('WebkulUserBundle:User')->find($data['id']);
            } else {
                $json['error'] = $this->translate('missing fields');   
                $json['description'] = $this->translate('required: id ');     
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);   
            }

            if ($agent) {
                if($ticket->getAgent() != $agent) {
                    $ticket->setAgent($agent);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $json['success'] = 'Success ! Ticket assigned to agent successfully.';
                    $statusCode = Response::HTTP_OK;
        
                    // Trigger ticket delete event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Agent::getId(), [
                        'entity' => $ticket,
                    ]);
        
                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
                    
                } else {
                    $json['error'] = 'invalid resource';
                    $json['description'] = $this->translate('Error ! Invalid agent.');
                    $statusCode = Response::HTTP_NOT_FOUND;
                }
            }
        } else {
            $json['error'] = $this->translate('invalid ticket');
            $statusCode = Response::HTTP_NOT_FOUND;
        }

        return new JsonResponse($json, $statusCode);  
    }

    /**
     * adding  or removing collaborator to a Ticket
     *
     * @param Request $request
     * @return void
    */

    public function addRemoveTicketCollaborator(Request $request) 
    {
        $json = [];
        $statusCode = Response::HTTP_OK;
        $content = $request->request->all()? : json_decode($request->getContent(), true);

        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('WebkulTicketBundle:Ticket')->find($request->attributes->get('id'));
        if(!$ticket) {
            $json['error'] = 'resource not found';
            return new JsonResponse($json, Response::HTTP_NOT_FOUND);
        }

        if($request->getMethod() == "POST") { 
            if(!isset($content['email']) || !filter_var($content['email'], FILTER_VALIDATE_EMAIL)) {
                $json['error'] = 'missing/invalid field';
                $json['message'] = 'required: email';
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }

            if($content['email'] == $ticket->getCustomer()->getEmail()) {
                $json['error'] = $this->get('translator')->trans('Error ! Can not add customer as a collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            } else {
                $data = array(
                    'from' => $content['email'],
                    'firstName' => ($firstName = ucfirst(current(explode('@', $content['email'])))),
                    'lastName' => ' ',
                    'role' => 4,
                );
                
                $supportRole = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode('ROLE_CUSTOMER');
                $collaborator = $this->get('user.service')->createUserInstance($data['from'], $data['firstName'], $supportRole, $extras = ["active" => true]);
                $checkTicket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->isTicketCollaborator($ticket, $content['email']);

                if (!$checkTicket) { 
                    $ticket->addCollaborator($collaborator);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $ticket->lastCollaborator = $collaborator;

                    if ($collaborator->getCustomerInstance())
                        $json['collaborator'] = $collaborator->getCustomerInstance()->getPartialDetails();
                    else
                        $json['collaborator'] = $collaborator->getAgentInstance()->getPartialDetails();

                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Collaborator::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success'] =  $this->get('translator')->trans('Success ! Collaborator added successfully.');
                    $statusCode = Response::HTTP_OK;
                } else {
                    $json['warning'] =  $this->get('translator')->trans('Collaborator is already added.');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                }
            }
        } elseif($request->getMethod() == "DELETE") {
            $collaborator = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneBy(array('id' => $request->attributes->get('id')));
            if($collaborator) {
                $ticket->removeCollaborator($collaborator);
                $entityManager->persist($ticket);
                $entityManager->flush();

                $json['success'] =  $this->get('translator')->trans('Success ! Collaborator removed successfully.');
                $statusCode = Response::HTTP_OK;
            } else {
                $json['error'] =  $this->get('translator')->trans('Error ! Invalid Collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        }

        return new JsonResponse($json, $statusCode);  
    }

    /**
     * objectSerializer This function convert Entity object into json contenxt
     * @param Object $object Customer Entity object
     * @return JSON  JSON context
     */
    public function objectSerializer($object) {
        $object->formatedCreatedAt = new \Datetime;
        $encoders = array(new XmlEncoder(), new JsonEncoder());
        $normalizer = new ObjectNormalizer();
        $normalizer->setCircularReferenceHandler(function ($object) {
            return $object->getId();
        });

        $normalizers = array($normalizer);
        $serializer = new Serializer($normalizers, $encoders);
        $jsonContent = $serializer->serialize($object, 'json');

        return $jsonContent;
    }
}
