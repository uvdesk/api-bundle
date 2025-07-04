<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity as CoreFrameworkBundleEntity;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

class Threads extends AbstractController
{
    /** Ticket Reply
     * @param Request $request
     */
    public function saveThread(Request $request, $ticketid, ContainerInterface $container, EntityManagerInterface $entityManager)
    {
        $data = $request->request->all() ?: json_decode($request->getContent(), true);

        if (
            ! isset($data['threadType'])
            || ! isset($data['message'])
            || ! isset($data['actAsType'])
        ) {
            $json['error'] = 'missing fields';
            $json['description'] = 'required: (threadType: reply|forward|note) , message, actAsType (customer|agent)';

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $ticket = $entityManager->getRepository(CoreFrameworkBundleEntity\Ticket::class)->findOneById($ticketid);

        // Check for empty ticket
        if (empty($ticket)) {
            $json['error'] = "Error! No such ticket with ticket id exist";

            return new JsonResponse($json, Response::HTTP_NOT_FOUND);
        } else if ('POST' != $request->getMethod()) {
            $json['error'] = "Error! invalid request method";

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        // Check if message content is empty
        $parsedMessage = trim(strip_tags($data['message'], '<img>'));
        $parsedMessage = str_replace('&nbsp;', '', $parsedMessage);
        $parsedMessage = str_replace(' ', '', $parsedMessage);

        if (null == $parsedMessage) {
            $json['error'] = "Warning ! Reply content cannot be left blank.";

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $actAsType = strtolower($data['actAsType']);
        $user = $entityManager->getRepository(CoreFrameworkBundleEntity\User::class)->findOneByEmail($data['actAsEmail']);

        if (! $user) {
            $json['error'] = 'Error! no user found.';

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if ($actAsType == 'agent') {
            $data['user'] = isset($user) && $user ? $user : $container->get('user.service')->getCurrentUser();
        } else {
            $data['user'] = $user;
        }

        $attachments = $request->files->get('attachments');

        if (! empty($attachments)) {
            $attachments = is_array($attachments) ? $attachments : [$attachments];
        }

        $threadDetails = [
            'user'          => $data['user'],
            'createdBy'     => $actAsType,
            'source'        => 'api',
            'threadType'    => strtolower($data['threadType']),
            'message'       => str_replace(['&lt;script&gt;', '&lt;/script&gt;'], '', $data['message']),
            'attachments'   => $attachments
        ];

        if (! empty($data['status'])) {
            $ticketStatus =  $entityManager->getRepository(CoreFrameworkBundleEntity\TicketStatus::class)->findOneByCode($data['status']);
            $ticket->setStatus($ticketStatus);
        }

        if (isset($data['to'])) {
            $threadDetails['to'] = $data['to'];
        }

        if (isset($data['cc'])) {
            $threadDetails['cc'] = $data['cc'];
        }

        if (isset($data['cccol'])) {
            $threadDetails['cccol'] = $data['cccol'];
        }

        if (isset($data['bcc'])) {
            $threadDetails['bcc'] = $data['bcc'];
        }

        $customer = $entityManager->getRepository(CoreFrameworkBundleEntity\UserInstance::class)->findOneBy(array('user' => $user->getId(), 'supportRole' => 4));

        if (
            ! empty($customer)
            && $threadDetails['createdBy'] == 'customer'
            && $threadDetails['threadType'] == 'note'
        ) {
            $json['success'] = "success', Can't add note user account.";

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if (
            ! empty($customer)
            && $threadDetails['createdBy'] == 'customer'
            && $threadDetails['threadType'] == 'forward'
        ) {
            $json['success'] = "success', Can't forward ticket to user account.";

            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        // Create Thread
        $thread = $container->get('ticket.service')->createThread($ticket, $threadDetails);

        // Check for thread types
        switch ($thread->getThreadType()) {
            case 'note':
                $event = new CoreWorkflowEvents\Ticket\Note();
                $event
                    ->setTicket($ticket)
                    ->setThread($thread)
                ;

                $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');
                $json['success'] = "success', Note added to ticket successfully.";

                return new JsonResponse($json, Response::HTTP_OK);

                break;
            case 'reply':
                if ($thread->getCreatedBy() == 'customer') {
                    $event = new CoreWorkflowEvents\Ticket\CustomerReply();
                    $event
                        ->setTicket($ticket)
                        ->setThread($thread)
                    ;
                } else {
                    $event = new CoreWorkflowEvents\Ticket\AgentReply();
                    $event
                        ->setTicket($ticket)
                        ->setThread($thread)
                    ;
                }

                $container->get('event_dispatcher')->dispatch($event, 'uvdesk.automation.workflow.execute');

                $json['success'] = "success', Reply added to ticket successfully..";
                $json['threadId'] = $thread->getId();
                $json['thread'] = $threadDetails;

                return new JsonResponse($json, Response::HTTP_OK);

                break;
            case 'forward':
                // Prepare headers
                $headers = ['References' => $ticket->getReferenceIds()];

                if (null != $ticket->currentThread->getMessageId()) {
                    $headers['In-Reply-To'] = $ticket->currentThread->getMessageId();
                }

                // Prepare attachments
                $attachments = $entityManager->getRepository(CoreFrameworkBundleEntity\Attachment::class)->findByThread($thread);

                $projectDir = $container->get('kernel')->getProjectDir();
                $attachments = array_map(function ($attachment) use ($projectDir) {
                    return str_replace('//', '/', $projectDir . "/public" . $attachment->getPath());
                }, $attachments);

                // Forward thread to users
                try {
                    $messageId = $container->get('email.service')->sendMail($params['subject'] ?? ("Forward: " . $ticket->getSubject()), $thread->getMessage(), $thread->getReplyTo(), $headers, $ticket->getMailboxEmail(), $attachments ?? [], $thread->getCc() ?: [], $thread->getBcc() ?: []);

                    if (! empty($messageId)) {
                        $thread->setMessageId($messageId);

                        $entityManager->persist($thread);
                        $entityManager->flush();
                    }
                } catch (\Exception $e) {
                    // Do nothing ...
                    // @TODO: Log exception
                }

                $json['success'] = "success', Reply added to the ticket and forwarded successfully.";

                return new JsonResponse($json, Response::HTTP_OK);

                break;
            default:
                break;
        }
    }
}
