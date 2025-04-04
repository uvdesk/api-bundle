<?php

namespace Webkul\UVDesk\ApiBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Webkul\UVDesk\CoreFrameworkBundle\Utils\TokenGenerator;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApiSettings extends AbstractController
{
    public function loadConfigurations(ContainerInterface $container)
    {
        if (!$container->get('user.service')->isAccessAuthorized('ROLE_ADMIN')) {
            throw new AccessDeniedException("Insufficient account privileges");
        }

        return $this->render('@UVDeskApi//accessCredentials.html.twig');
    }

    public function loadConfigurationsXHR(Request $request, UserInterface $user, EntityManagerInterface $entityManager)
    {
        if (!empty($user)) {
            $collection = array_map(function ($accessCredential) {
                return [
                    'id'          => $accessCredential->getId(),
                    'name'        => $accessCredential->getName(),
                    'token'       => $accessCredential->getToken(),
                    'dateCreated' => $accessCredential->getCreatedOn()->format('(l) F d, Y \a\t H:i:s'),
                    'isEnabled'   => $accessCredential->getIsEnabled(),
                ];
            }, $entityManager->getRepository(ApiAccessCredential::class)->findBy(['user' => $user]));
        }

        return new JsonResponse($collection ?? []);
    }

    public function createAccessCredentials(Request $request, UserInterface $user, EntityManagerInterface $entityManager)
    {
        if ($request->getMethod() == 'POST') {
            $params = $request->request->all();

            if (!empty($params['name']) && !empty($user)) {
                ($accessCredential = new ApiAccessCredential())
                    ->setUser($user)
                    ->setName($params['name'])
                    ->setToken(strtoupper(TokenGenerator::generateToken(64)))
                    ->setCreatedOn(new \DateTime('now'))
                    ->setIsEnabled(true)
                    ->setIsExpired(false);

                $entityManager->persist($accessCredential);
                $entityManager->flush();
            }

            $this->addFlash('success', 'Api access credentials created successfully.');

            return new RedirectResponse($this->generateUrl('uvdesk_api_load_configurations'));
        }

        return $this->render('@UVDeskApi//accessCredentialSettings.html.twig');
    }

    public function updateAccessCredentialsXHR(Request $request, UserInterface $user, EntityManagerInterface $entityManager)
    {
        $params = $request->request->all();

        if (empty($params)) {
            return new JsonResponse([], 404);
        } else {
            $accessCredential = $entityManager->getRepository(ApiAccessCredential::class)->findOneById($params['id']);

            if (empty($accessCredential) || $accessCredential->getUser()->getId() != $user->getId()) {
                return new JsonResponse([], 404);
            }
        }

        switch ($request->getMethod()) {
            case 'PATCH':
                $accessCredential
                    ->setIsEnabled(("false" == $params['isEnabled']) ? false : true)
                    ->setIsExpired(("false" == $params['isEnabled']) ? true : false);
                
                $entityManager->persist($accessCredential);
                $entityManager->flush();
                break;
            case 'DELETE':
                $entityManager->remove($accessCredential);
                $entityManager->flush();
                break;
            default:
                break;
        }

        return new JsonResponse([]);
    }
}
