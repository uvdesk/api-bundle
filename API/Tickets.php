<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Attachment;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportGroup;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportLabel;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportRole;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportTeam;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Ticket;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketPriority;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketStatus;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketType;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UVDeskService;

class Tickets extends AbstractController
{
    /**
     * Return support tickets.
     *
     * @param Request $request
     */
    public function fetchTickets(Request $request, ContainerInterface $container, UVDeskService $uvdesk)
    {
        $json = [];
        $entityManager = $this->getDoctrine()->getManager();
        
        $ticketRepository = $this->getDoctrine()->getRepository(Ticket::class);
        $userRepository = $this->getDoctrine()->getRepository(User::class);

        if ($request->query->get('actAsType')) {
            switch ($request->query->get('actAsType')) {
                case 'customer': 
                    $email = $request->query->get('actAsEmail');
                    $customer = $entityManager->getRepository(User::class)->findOneByEmail($email);

                    if ($customer) {
                        $json = $ticketRepository->getAllCustomerTickets($request->query, $container, $customer);
                    } else {
                        $json['error'] = $container->get('translator')->trans('Error! Resource not found.');
                        
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }

                    return new JsonResponse($json);
                case 'agent':
                    $email = $request->query->get('actAsEmail');
                    $user = $entityManager->getRepository(User::class)->findOneByEmail($email);
                    
                    if ($user) {
                        $request->query->set('agent', $user->getId());
                    } else {
                        $json['error'] = $container->get('translator')->trans('Error! Resource not found.');
                        
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }

                    break;
                default:
                    $json['error'] = $container->get('translator')->trans('Error! invalid actAs details.');

                    return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }
        }

        $json = $ticketRepository->getAllTickets($request->query, $container);

        $collection = $json['tickets'];
        $pagination = $json['pagination'];

        // Resolve asset paths
        $defaultAgentProfileImagePath = $this->getParameter('assets_default_agent_profile_image_path');
        $defaultCustomerProfileImagePath = $this->getParameter('assets_default_customer_profile_image_path');

        $user = $this->getUser();
        $userInstance = $user->getCurrentInstance();

        $currentUserDetails = [
            'id'               => $user->getId(), 
            'email'            => $user->getEmail(), 
            'name'             => $user->getFirstName() . ' ' . $user->getLastname(), 
            'profileImagePath' => $uvdesk->generateCompleteLocalResourcePathUri($userInstance->getProfileImagePath() ?? $defaultAgentProfileImagePath)
        ];

        foreach ($collection as $index => $ticket) {
            // Resolve assets: Assigned agent
            if (!empty($ticket['agent'])) {
                $profileImagePath = $uvdesk->generateCompleteLocalResourcePathUri($ticket['agent']['profileImagePath'] ?? $defaultAgentProfileImagePath);
                $smallThumbnailPath = $uvdesk->generateCompleteLocalResourcePathUri($ticket['agent']['smallThumbnail'] ?? $defaultAgentProfileImagePath);

                $collection[$index]['agent']['profileImagePath'] = $profileImagePath;
                $collection[$index]['agent']['smallThumbnail'] = $smallThumbnailPath;
            }

            // Resolve assets: Customer
            if (!empty($ticket['customer'])) {
                $profileImagePath = $uvdesk->generateCompleteLocalResourcePathUri($ticket['customer']['profileImagePath'] ?? $defaultCustomerProfileImagePath);
                $smallThumbnailPath = $uvdesk->generateCompleteLocalResourcePathUri($ticket['customer']['smallThumbnail'] ?? $defaultCustomerProfileImagePath);

                $collection[$index]['customer']['profileImagePath'] = $profileImagePath;
                $collection[$index]['customer']['smallThumbnail'] = $smallThumbnailPath;
            }
        }

        // Available helpdesk agents collection
        $agents = $container->get('user.service')->getAgentsPartialDetails();

        return new JsonResponse([
            'tickets'     => $collection, 
            'pagination'  => $pagination, 
            'userDetails' => $currentUserDetails, 
            'agents'      => $agents, 
            'status'      => $container->get('ticket.service')->getStatus(), 
            'group'       => $userRepository->getSupportGroups(), 
            'team'        =>  $userRepository->getSupportTeams(), 
            'priority'    => $container->get('ticket.service')->getPriorities(), 
            'type'        => $container->get('ticket.service')->getTypes(), 
            'source'      => $container->get('ticket.service')->getAllSources(), 
        ]);

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
    public function trashTicket(Request $request, ContainerInterface $container)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository(Ticket::class)->find($ticketId);
        
        if (!$ticket) {
            throw new NotFoundHttpException('Page Not Found');
        }

        if (!$ticket->getIsTrashed()) {
            $ticket->setIsTrashed(1);
            $entityManager->persist($ticket);
            $entityManager->flush();

            $json['success'] = $container->get('translator')->trans('Success ! Ticket moved to trash successfully.');
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new CoreWorkflowEvents\Ticket\Delete();
            $event
                ->setTicket($ticket)
            ;

            $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');
        } else {
            $json['error'] = $container->get('translator')->trans('Warning ! Ticket is already in trash.');
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
    public function createTicket(Request $request, ContainerInterface $container)
    {
        $data = $request->request->all()? : json_decode($request->getContent(),true);
        foreach ($data as $key => $value) {
            if (!in_array($key, ['subject', 'group', 'type', 'status','locale','domain', 'priority', 'agent', 'replies', 'createdAt', 'updatedAt', 'customFields', 'files', 'from', 'name', 'message', 'tags', 'actAsType', 'actAsEmail'])) {
                unset($data[$key]);
            }
        }
  
        if (
            !(isset($data['from']) 
            && isset($data['name']) 
            && isset($data['subject']) 
            && isset($data['message']) 
            &&  isset($data['actAsType']) 
            || isset($data['actAsEmail']) )
        ) {
            $json['error'] = $container->get('translator')->trans('required fields: name ,from, message, actAsType or actAsEmail');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if ($data) {
            $error = false;
            $message = '';
            $entityManager = $this->getDoctrine()->getManager();

            if ($data['subject'] == '') {
                $message = $container->get('translator')->trans("Warning! Please complete subject field value!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif ($data['message'] == '') {
                $json['message'] = $container->get('translator')->trans("Warning! Please complete message field value!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif (filter_var($data['from'], FILTER_VALIDATE_EMAIL) === false) {
                $json['message'] = $container->get('translator')->trans("Warning! Invalid from Email Address!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif ($data['actAsType'] == ''  &&  $data['actAsEmail'] == '') {
                $json['message'] = $container->get('translator')->trans("Warning! Provide atleast one parameter actAsType(agent or customer) or actAsEmail");
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
            
            if (! $error) {
                $name = explode(' ', trim($data['name']));
                $ticketData['firstName'] = $name[0];
                $ticketData['lastName']  = isset($name[1]) ? $name[1] : '';
                $ticketData['role']      = 4;
             
                if (
                    (array_key_exists('actAsType', $data))
                    && (strtolower($data['actAsType']) == 'customer')
                ) {
                    $actAsType = strtolower(trim($data['actAsType']));         
                } else if (
                    (array_key_exists('actAsEmail', $data)) 
                    && (strtolower(trim($data['actAsType'])) == 'agent')
                ) {
                    $user = $entityManager->getRepository(User::class)->findOneByEmail(trim($data['actAsEmail']));
                    
                    if ($user) {
                        $actAsType = 'agent';
                    } else {
                        $json['error'] = $container->get('translator')->trans("Error ! actAsEmail is not valid");
                        return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
                    }
                } else {
                    $json['warning'] = $container->get('translator')->trans('Warning ! For Customer specify actAsType as customer and for Agent specify both parameter actASType  as agent and actAsEmail as agent email');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                }
                
                // Create customer if account does not exists
                $customer = $entityManager->getRepository(User::class)->findOneByEmail($data['from']);
             
                if (empty($customer) || null == $customer->getCustomerInstance()) {
                    $role = $entityManager->getRepository(SupportRole::class)->findOneByCode('ROLE_CUSTOMER');
                  
                    // Create User Instance
                    $customer = $container->get('user.service')->createUserInstance($data['from'], $data['name'], $role, [
                        'source' => 'api',
                        'active' => true
                    ]);
                }

                if ($actAsType == 'agent') {
                    $data['user'] = isset($user) && $user ? $user : $container->get('user.service')->getCurrentUser();
                } else {
                    $data['user'] = $customer;
                }

                $attachments = $request->files->get('attachments');
                if (! empty($attachments)) {
                    $attachments = is_array($attachments) ? $attachments : [$attachments];
                }
                
                $ticketData['user']        = $data['user'];
                $ticketData['subject']     = trim($data['subject']);
                $ticketData['message']     = trim($data['message']);
                $ticketData['customer']    = $customer;
                $ticketData['source']      = 'api';
                $ticketData['threadType']  = 'create';
                $ticketData['createdBy']   = $actAsType;
                $ticketData['attachments'] = $attachments;
                
                $extraKeys = ['tags', 'group', 'priority', 'status', 'agent', 'createdAt', 'updatedAt'];

                if (array_key_exists('type', $data)) {
                    $ticketType = $entityManager->getRepository(TicketType::class)->findOneByCode($data['type']);
                    $ticketData['type'] = $ticketType;
                }
                
                $requestData = $data;
                foreach ($extraKeys as $key) {
                    if (isset($ticketData[$key])) {
                        unset($ticketData[$key]);
                    }
                }
                
                $thread = $container->get('ticket.service')->createTicketBase($ticketData);
                // Trigger ticket created event
                try {
                    $event = new CoreWorkflowEvents\Ticket\Create();
                    $event
                        ->setTicket($thread->getTicket())
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');
                } catch (\Exception $e) {
                    //
                }

                $json['message'] = $container->get('translator')->trans('Success ! Ticket has been created successfully.');
                $json['ticketId'] = $thread->getTicket()->getId();
                $statusCode = Response::HTTP_OK;

            } else {
                $json['message'] = $container->get('translator')->trans('Warning ! Required parameters should not be blank');
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        } else {
            $json['error'] = $container->get('translator')->trans('invalid/empty size of Request');
            $json['message'] = $container->get('translator')->trans('Warning ! Post size can not exceed 25MB');
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
    public function viewTicket($ticketId, Request $request, ContainerInterface $container, UVDeskService $uvdesk)
    {
        $entityManager = $this->getDoctrine()->getManager();

        $userRepository = $entityManager->getRepository(User::class);
        $ticketRepository = $entityManager->getRepository(Ticket::class);

        $ticket = $ticketRepository->findOneById($ticketId);

        if (empty($ticket)) {
            throw new \Exception('Page not found');
        }

        $user = $this->getUser();
        $userInstance = $user->getCurrentInstance();

        $agent = $ticket->getAgent();
        $customer = $ticket->getCustomer();

        $defaultAgentProfileImagePath = $this->getParameter('assets_default_agent_profile_image_path');
        $defaultCustomerProfileImagePath = $this->getParameter('assets_default_customer_profile_image_path');

        $agentDetails = !empty($agent) ? $agent->getAgentInstance()->getPartialDetails() : null;
        $customerDetails = $customer->getCustomerInstance()->getPartialDetails();

        if (! empty($agentDetails)) {
            $agentDetails['thumbnail'] = $uvdesk->generateCompleteLocalResourcePathUri($agentDetails['thumbnail'] ?? $defaultAgentProfileImagePath);
        }

        if (! empty($agentDetails)) {
            $customerDetails['thumbnail'] = $uvdesk->generateCompleteLocalResourcePathUri($customerDetails['thumbnail'] ?? $defaultCustomerProfileImagePath);
        }

        // Mark as viewed by agents
        if (false == $ticket->getIsAgentViewed()) {
            $ticket
                ->setIsAgentViewed(true)
            ;

            $entityManager->persist($ticket);
            $entityManager->flush();
        }

        // Ticket status Collection
        $status = array_map(function ($statusCollection) {
            return [
                'id'          => $statusCollection->getId(),
                'code'        => $statusCollection->getCode(),
                'colorCode'   => $statusCollection->getColorCode(),
                'description' => $statusCollection->getDescription(),
            ];
        }, $entityManager->getRepository(TicketStatus::class)->findAll());

        // Ticket Type Collection
        $type = array_map(function ($ticketTypeCollection) {
            return [
                'id'          => $ticketTypeCollection->getId(),
                'code'        => $ticketTypeCollection->getCode(),
                'isActive'    => $ticketTypeCollection->getIsActive(),
                'description' => $ticketTypeCollection->getDescription(),
            ];
        }, $entityManager->getRepository(TicketType::class)->findByIsActive(true));

        // Priority Collection
        $priority = array_map(function ($ticketPriorityCollection) {
            return [
                'id'          => $ticketPriorityCollection->getId(),
                'code'        => $ticketPriorityCollection->getCode(),
                'colorCode'   => $ticketPriorityCollection->getColorCode(),
                'description' => $ticketPriorityCollection->getDescription(),
            ];
        }, $entityManager->getRepository(TicketPriority::class)->findAll());
      
        $userService = $container->get('user.service');
        $fileSystemService =  $container->get('uvdesk.core.file_system.service');

        $supportGroup = $ticket->getSupportGroup();

        if (! empty($supportGroup)) {
            $supportGroup = [
                'id'   => $supportGroup->getId(), 
                'name' => $supportGroup->getName(), 
            ];
        }

        $supportTeam = $ticket->getSupportTeam();

        if (! empty($supportTeam)) {
            $supportTeam = [
                'id'   => $supportTeam->getId(), 
                'name' => $supportTeam->getName(), 
            ];
        }

        $ticketDetails = [
            'id'               => $ticket->getId(), 
            'source'           => $ticket->getSource(), 
            'priority'         => $ticket->getPriority()->getId(), 
            'status'           => $ticket->getStatus()->getId(), 
            'subject'          => $ticket->getSubject(), 
            'isNew'            => $ticket->getIsNew(), 
            'isReplied'        => $ticket->getIsReplied(), 
            'isReplyEnabled'   => $ticket->getIsReplyEnabled(), 
            'isStarred'        => $ticket->getIsStarred(), 
            'isTrashed'        => $ticket->getIsTrashed(), 
            'isAgentViewed'    => $ticket->getIsAgentViewed(), 
            'isCustomerViewed' => $ticket->getIsCustomerViewed(), 
            'createdAt'        => $userService->getLocalizedFormattedTime($ticket->getCreatedAt(), $user), 
            'updatedAt'        => $userService->getLocalizedFormattedTime($ticket->getUpdatedAt(), $user), 
            'group'            => $supportGroup, 
            'team'             => $supportTeam, 
        ];

        $threads = array_map(function ($thread) use ($uvdesk, $userService, $fileSystemService, $defaultAgentProfileImagePath, $defaultCustomerProfileImagePath) {
            $user = $thread->getUser();
            $userInstance = $thread->getCreatedBy() == 'agent' ? $user->getAgentInstance() : $user->getCustomerInstance();

            $attachments = array_map(function ($attachment) use ($fileSystemService) {
                return $fileSystemService->getFileTypeAssociations($attachment);
            }, $thread->getAttachments()->getValues());

            $thumbnail = $uvdesk->generateCompleteLocalResourcePathUri($userInstance->getProfileImagePath() ?? ($thread->getCreatedBy() == 'agent' ? $defaultAgentProfileImagePath : $defaultCustomerProfileImagePath));

            return [
                'id'           => $thread->getId(),
                'source'       => $thread->getSource(),
                'threadType'   => $thread->getThreadType(),
                'createdBy'    => $thread->getCreatedBy(),
                'cc'           => $thread->getCc(),
                'bcc'          => $thread->getBcc(),
                'isLocked'     => $thread->getIsLocked(),
                'isBookmarked' => $thread->getIsBookmarked(),
                'message'      => $thread->getMessage(),
                'source'       => $thread->getSource(),
                'createdAt'    => $userService->getLocalizedFormattedTime($thread->getCreatedAt(), $user), 
                'updatedAt'    => $userService->getLocalizedFormattedTime($thread->getUpdatedAt(), $user), 
                'user' => [
                    'id'        => $user->getId(),
                    'name'      => $user->getFullName(),
                    'email'     => $user->getEmail(),
                    'thumbnail' => $thumbnail,
                ], 
                'attachments' => $attachments,
            ];
        }, $ticket->getThreads()->getValues());

        $ticketDetails['threads'] = $threads;
        $ticketDetails['agent'] = $agentDetails;
        $ticketDetails['customer'] = $customerDetails;
        $ticketDetails['totalThreads'] = count($threads);
        
        return new JsonResponse([
            'ticket'                => $ticketDetails,
            'totalCustomerTickets'  => ($ticketRepository->countCustomerTotalTickets($customer, $container)),
            'supportGroups'         => $userRepository->getSupportGroups(),
            'supportTeams'          => $userRepository->getSupportTeams(),
            'ticketStatuses'        => $status,
            'ticketPriorities'      => $priority,
            'ticketTypes'           => $type
        ]);
    }

    /**
     * delete support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function deleteTicketForever(Request $request, ContainerInterface $container)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository(Ticket::class)->find($ticketId);
        
        if (! $ticket) {
            throw new NotFoundHttpException('Page Not Found');
        }

        if ($ticket->getIsTrashed()) {
            $entityManager->remove($ticket);
            $entityManager->flush();

            $json['success'] = $container->get('translator')->trans('Success ! Ticket removed successfully.');
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new CoreWorkflowEvents\Ticket\Delete();
            $event
                ->setTicket($ticket)
            ;

            $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');
        } else {
            $json['error'] = $container->get('translator')->trans('Warning ! something went wrong.');
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
    public function assignAgent(Request $request, ContainerInterface $container)
    {
        $json = [];
        $data = $request->request->all() ? :json_decode($request->getContent(), true);
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository(Ticket::class)->findOneBy(array('id' => $ticketId));
    
        if ($ticket) {
            if (isset($data['id'])) {
                $agent = $entityManager->getRepository(User::class)->find($data['id']);
            } else {
                $json['error'] = $container->get('translator')->trans('missing fields');   
                $json['description'] = $container->get('translator')->trans('required: id ');     
               
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);   
            }
           
            if ($agent) {
                if ($ticket->getAgent() != $agent) {
                    if ($ticket->getIsTrashed()) {
                        $json['status'] = false;
                        $json['error'] = $container->get('translator')->trans('Tickets is in trashed can not assign to agent.'); 
                        
                        return new JsonResponse($json, Response::HTTP_BAD_REQUEST);   
                    }
                    
                    $ticket->setAgent($agent);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $json['success'] = $container->get('translator')->trans('Success ! Ticket assigned to agent successfully.');
                    $statusCode = Response::HTTP_OK;
        
                    // Trigger ticket delete event
                    $event = new CoreWorkflowEvents\Ticket\Agent();
                    $event
                        ->setTicket($ticket)
                    ;
        
                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');
                    
                } else {
                    $json['error'] = $container->get('translator')->trans('invalid resource');
                    $json['description'] = $container->get('translator')->trans('Error ! Invalid agent or already assigned for this ticket');
                    $statusCode = Response::HTTP_NOT_FOUND;
                }
            }
        } else {
            $json['error'] = $container->get('translator')->trans('invalid ticket');
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

    public function addRemoveTicketCollaborator(Request $request, ContainerInterface $container) 
    {
        $json = [];
        $statusCode = Response::HTTP_OK;
        $content = $request->request->all()? : json_decode($request->getContent(), true);

        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository(Ticket::class)->find($request->attributes->get('ticketId'));
        if (!$ticket) {
            $json['error'] =  $container->get('translator')->trans('resource not found');
            
            return new JsonResponse($json, Response::HTTP_NOT_FOUND);
        }

        if (
            $request->getMethod() == "POST" 
            && !(isset($content['id'])) 
        ) {
            if (
                !isset($content['email']) 
                || !filter_var($content['email'], FILTER_VALIDATE_EMAIL)
            ) {
                $json['error'] = $container->get('translator')->trans('missing/invalid field');
                $json['message'] = $container->get('translator')->trans('required: email');
                
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }

            if ($content['email'] == $ticket->getCustomer()->getEmail()) {
                $json['error'] = $container->get('translator')->trans('Error ! Can not add customer as a collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            } else {
                $data = array(
                    'from'      => $content['email'],
                    'firstName' => ($firstName = ucfirst(current(explode('@', $content['email'])))),
                    'lastName'  => '',
                    'role'      => 4,
                );
                
                $supportRole = $entityManager->getRepository(SupportRole::class)->findOneByCode('ROLE_CUSTOMER');
                $collaborator = $container->get('user.service')->createUserInstance($data['from'], $data['firstName'], $supportRole, $extras = ["active" => true]);
                $checkTicket = $entityManager->getRepository(Ticket::class)->isTicketCollaborator($ticket, $content['email']);

                if (!$checkTicket) { 
                    $ticket->addCollaborator($collaborator);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $ticket->lastCollaborator = $collaborator;

                    if ($collaborator->getCustomerInstance()) {
                        $json['collaborator'] = $collaborator->getCustomerInstance()->getPartialDetails();
                    } else {
                        $json['collaborator'] = $collaborator->getAgentInstance()->getPartialDetails();
                    }

                    $event = new CoreWorkflowEvents\Ticket\Collaborator();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success'] =  $container->get('translator')->trans('Success ! Collaborator added successfully.');
                    $statusCode = Response::HTTP_OK;
                } else {
                    $json['warning'] =  $container->get('translator')->trans('Collaborator is already added.');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                }
            }
        } elseif ($request->getMethod() == "POST"  &&  isset($content['id']) ) {
            $collaborator = $entityManager->getRepository(User::class)->findOneBy(array('id' => $content['id']));
            
            if ($collaborator) {
                $ticket->removeCollaborator($collaborator);
                $entityManager->persist($ticket);
                $entityManager->flush();

                $json['success'] =  $container->get('translator')->trans('Success ! Collaborator removed successfully.');
                $statusCode = Response::HTTP_OK;
            } else {
                $json['error'] =  $container->get('translator')->trans('Error ! Invalid Collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        }

        return new JsonResponse($json, $statusCode);  
    }
    
    /**
     * Download ticket attachment
     *
     * @param Request $request
     * @return void
    */
    public function downloadAttachment(Request $request, ContainerInterface $container) 
    {
        $attachmentId = $request->attributes->get('attachmentId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository(Attachment::class);
        $attachment = $attachmentRepository->findOneById($attachmentId);
        $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();

        if (!$attachment) {
            throw new NotFoundHttpException('Page Not Found');
        }

        $path = $container->get('kernel')->getProjectDir() . "/public/". $attachment->getPath();

        $response = new Response();
        $response->setStatusCode(200);

        $response->headers->set('Content-type', $attachment->getContentType());
        $response->headers->set('Content-Disposition', 'attachment; filename='. $attachment->getName());
        $response->sendHeaders();
        $response->setContent(readfile($path));

        return $response; 
    }

    /**
     * Download Zip attachment
     *
     * @param Request $request
     * @return void
    */
    public function downloadZipAttachment(Request $request)
    {
        $threadId = $request->attributes->get('threadId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository(Attachment::class);

        $attachment = $attachmentRepository->findByThread($threadId);

        if (!$attachment) {
            throw new NotFoundHttpException('Page Not Found');
        }

        $zipname = 'attachments/' .$threadId.'.zip';
        $zip = new \ZipArchive;

        $zip->open($zipname, \ZipArchive::CREATE);
        if (count($attachment)) {
            foreach ($attachment as $attach) {
                $zip->addFile(substr($attach->getPath(), 1));
            }
        }

        $zip->close();

        $response = new Response();
        $response->setStatusCode(200);
        $response->headers->set('Content-type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename=' . $threadId . '.zip');
        $response->sendHeaders();
        $response->setContent(readfile($zipname));

        return $response;
    }

    /**
     * Edit Ticket properties
     *
     * @param Request $request
     * @return void
    */
    public function editTicketProperties(Request $request, ContainerInterface $container) 
    {
        $json = [];
        $statusCode = Response::HTTP_OK;

        $entityManager = $this->getDoctrine()->getManager();
        $requestContent = $request->request->all() ?: json_decode($request->getContent(), true);
        $ticketId =  $request->attributes->get('ticketId');
        $ticket = $entityManager->getRepository(Ticket::class)->findOneById($ticketId);
        // Validate request integrity
        if (empty($ticket)) {
            $json['error']  = 'invalid resource';
            $json['description'] =  $container->get('translator')->trans('Unable to retrieve details for ticket #%ticketId%.', [
                                        '%ticketId%' => $ticketId,
                                    ]);
            $statusCode = Response::HTTP_NOT_FOUND;
            
            return new JsonResponse($json, $statusCode);  
        } else if (!isset($requestContent['property'])) {
            $json['error']  =  $container->get('translator')->trans('missing resource');
            $json['description'] = $container->get('translator')->trans('Insufficient details provided.');
            $statusCode = Response::HTTP_BAD_REQUEST;
            
            return new JsonResponse($json, $statusCode); 
        }
        // Update property
        switch ($requestContent['property']) {
            case 'agent':
                $agent = $entityManager->getRepository(User::class)->findOneById($requestContent['value']);
                if (empty($agent)) {
                    // User does not exist
                    $json['error']  = $container->get('translator')->trans('No such user exist');
                    $json['description'] = $container->get('translator')->trans('Unable to retrieve agent details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    // Check if an agent instance exists for the user
                    $agentInstance = $agent->getAgentInstance();
                    if (empty($agentInstance)) {
                        // Agent does not exist
                        $json['error']  = $container->get('translator')->trans('No such user exist');
                        $json['description'] = $container->get('translator')->trans('Unable to retrieve agent details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                        
                        return new JsonResponse($json, $statusCode);
                    }
                }

                $agentDetails = $agentInstance->getPartialDetails();

                // Check if ticket is already assigned to the agent
                if (
                    $ticket->getAgent() 
                    && $agent->getId() === $ticket->getAgent()->getId()
                ) {
                    $json['success']  = $container->get('translator')->trans('Already assigned');
                    $json['description'] = $container->get('translator')->trans('Ticket already assigned to %agent%', [
                        '%agent%' => $agentDetails['name']]);
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setAgent($agent);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger Agent Assign event
                    $event = new CoreWorkflowEvents\Ticket\Agent();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket successfully assigned to %agent%', [
                        '%agent%' => $agentDetails['name'],
                    ]);
                    $statusCode = Response::HTTP_OK;
                   
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'status':
                $ticketStatus = $entityManager->getRepository(TicketStatus::class)->findOneById((int) $requestContent['value']);

                if (empty($ticketStatus)) {
                    // Selected ticket status does not exist
                    $json['error']  = $container->get('translator')->trans('Error');
                    $json['description'] = $container->get('translator')->trans('Unable to retrieve status details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                }

                if ($ticketStatus->getId() === $ticket->getStatus()->getId()) {
                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket status already set to %status%', [
                        '%status%' => $ticketStatus->getDescription()]);
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setStatus($ticketStatus);

                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket status event
                    $event = new CoreWorkflowEvents\Ticket\Status();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  =  $container->get('translator')->trans('Success');
                    $json['description'] =  $container->get('translator')->trans('Ticket status update to %status%', [
                        '%status%' => $ticketStatus->getDescription()]);
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'priority':
                // $container->isAuthorized('ROLE_AGENT_UPDATE_TICKET_PRIORITY');
                $ticketPriority = $entityManager->getRepository(TicketPriority::class)->findOneById($requestContent['value']);

                if (empty($ticketPriority)) {
                    // Selected ticket priority does not exist
                    $json['error']  = $container->get('translator')->trans('Error');
                    $json['description'] =  $container->get('translator')->trans('Unable to retrieve priority details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                }

                if ($ticketPriority->getId() === $ticket->getPriority()->getId()) {
                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] =  $container->get('translator')->trans('Ticket priority already set to %priority%', [
                        '%priority%' => $ticketPriority->getDescription()
                    ]);
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setPriority($ticketPriority);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket Priority event
                    $event = new CoreWorkflowEvents\Ticket\Priority();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] =  $container->get('translator')->trans('Ticket priority updated to %priority%', [
                        '%priority%' => $ticketPriority->getDescription()
                    ]);
                    $statusCode = Response::HTTP_OK;

                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'group':
                $supportGroup = $entityManager->getRepository(SupportGroup::class)->findOneById($requestContent['value']);

                if (empty($supportGroup)) {
                    if ($requestContent['value'] == "") {
                        if ($ticket->getSupportGroup() != null) {
                            $ticket->setSupportGroup(null);
                            $entityManager->persist($ticket);
                            $entityManager->flush();
                        }

                        $json['success']  = $container->get('translator')->trans('Success');
                        $json['description'] =   $container->get('translator')->trans('Ticket support group updated successfully');
                        $statusCode = Response::HTTP_OK;
                    } else {
                        $json['error']  = $container->get('translator')->trans('Error');
                        $json['description'] = $container->get('translator')->trans('Unable to retrieve support group details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                    }

                    return new JsonResponse($json, $statusCode);
                }

                if (
                    ! empty($ticket->getSupportGroup())
                    && $supportGroup->getId() === $ticket->getSupportGroup()->getId()
                ) {
                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket already assigned to support group');
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setSupportGroup($supportGroup);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger Support group event
                    $event = new CoreWorkflowEvents\Ticket\Group();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  = $container->get('translator')->trans('Success');
                    
                    $json['description'] = $container->get('translator')->trans('Ticket assigned to support group successfully');
                    $json['description'] = $container->get('translator')->trans('Ticket assigned to support group %group%', [
                        '%group%' => $supportGroup->getDescription()
                    ]);
                    
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'team':
                $supportTeam = $entityManager->getRepository(SupportTeam::class)->findOneById($requestContent['value']);

                if (empty($supportTeam)) {
                    if ($requestContent['value'] == "") {
                        if ($ticket->getSupportTeam() != null) {
                            $ticket->setSupportTeam(null);
                            $entityManager->persist($ticket);
                            $entityManager->flush();
                        }

                        $json['success']  = $container->get('translator')->trans('Success');
                        $json['description'] = $container->get('translator')->trans('Ticket support team updated successfully');
                        $statusCode = Response::HTTP_OK;
                        
                        return new JsonResponse($json, $statusCode);
                    } else {
                        $json['error']  = $container->get('translator')->trans('Error');
                        $json['description'] = $container->get('translator')->trans('Unable to retrieve support team details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                        
                        return new JsonResponse($json, $statusCode);
                    }
                }

                if (
                    ! empty($ticket->getSupportTeam())
                    && $supportTeam->getId() === $ticket->getSupportTeam()->getId()
                ) {
                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket already assigned to support team');
                    $statusCode = Response::HTTP_OK;
                        
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setSupportTeam($supportTeam);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket delete event
                    $event = new CoreWorkflowEvents\Ticket\Team();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket assigned to support team successfully');
                    $json['description'] = $container->get('translator')->trans('Ticket assigned to support team %team%', [
                        '%team%' => $supportTeam->getDescription()
                    ]);

                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'type':
                // $container->isAuthorized('ROLE_AGENT_UPDATE_TICKET_TYPE');
                $ticketType = $entityManager->getRepository(TicketType::class)->findOneById($requestContent['value']);

                if (empty($ticketType)) {
                    // Selected ticket priority does not exist
                    $json['error']  = $container->get('translator')->trans('Error');
                    $json['description'] = $container->get('translator')->trans('Unable to retrieve ticket type details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                }

                if (
                    ! empty($ticket->getType())
                    && $ticketType->getId() === $ticket->getType()->getId()
                ) {
                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket type already set to ' . $ticketType->getDescription());
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setType($ticketType);

                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket delete event
                    $event = new CoreWorkflowEvents\Ticket\Type();
                    $event
                        ->setTicket($ticket)
                    ;

                    $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Ticket type updated to ' . $ticketType->getDescription());
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'label':
                $label = $entityManager->getRepository(SupportLabel::class)->find($requestContent['value']);
                if ($label) {
                    $ticket->removeSupportLabel($label);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $json['success']  = $container->get('translator')->trans('Success');
                    $json['description'] = $container->get('translator')->trans('Success ! Ticket to label removed successfully');
                    $statusCode = Response::HTTP_OK;
                    
                    return new JsonResponse($json, $statusCode);
                } else {
                    $json['error']  = $container->get('translator')->trans('Error');
                    $json['description'] = $container->get('translator')->trans('No support level exist for this ticket with this id');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    
                    return new JsonResponse($json, $statusCode);
                }
                break;
            default:
                break;
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
        $normalizers = array($normalizer);
        
        $serializer = new Serializer($normalizers, $encoders);
        $jsonContent = $serializer->serialize($object, 'json', [
            'circular_reference_handler' => function ($object) {
                return $object->getId();
            }
        ]);

        return $jsonContent;
    }
}
