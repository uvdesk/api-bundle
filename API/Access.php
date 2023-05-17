<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Webkul\UVDesk\CoreFrameworkBundle\Utils\TokenGenerator;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;


class Access extends AbstractController
{
    public function validateSession(Request $request, EntityManagerInterface $entityManager, UserPasswordEncoderInterface $passwordEncoder)
    {
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        if (!empty($email) && !empty($password)) {
            $user = $this->getDoctrine()->getRepository(User::class)->findOneByEmail($email);
            $password = $passwordEncoder->encodePassword($user,$password);

            if (!empty($user) && !empty($password)) {
                $accessToken = strtoupper(TokenGenerator::generateToken(64));
                ($accessCredential = new ApiAccessCredential())
                ->setUser($user)
                ->setName($request->request->get('name'))
                ->setToken($accessToken)
                ->setCreatedOn(new \DateTime('now'))
                ->setIsEnabled(true)
                ->setIsExpired(false);

                $entityManager->persist($accessCredential);
                $entityManager->flush();

                return $this->json([
                    'status' => true,
                    'name' => $user->getUsername(),
                    'token'=>$accessToken
                ]);
            } else {
                return $this->json([
                    'status' => false,
                    'message' => 'Invalid credentials provided.'
                ]);
            }
        } else {
            return $this->json([
                'status' => false,
                'message' => 'Email and Password are mandatory.'
            ]);
        }
    }

    public function invalidateSesssion(Request $request)
    {
        $entityManager = $this->getDoctrine()->getManager();

        if (strpos(strtolower($request->headers->get('Authorization')), 'basic') === 0) {
            $authorizationToken = substr($request->headers->get('Authorization'), 6);

        } else if (strpos(strtolower($request->headers->get('Authorization')), 'bearer') === 0) {
            $authorizationToken = substr($request->headers->get('Authorization'), 7);
        }

        $accessToken = $entityManager->getRepository(ApiAccessCredential::class)->findOneByToken($authorizationToken);
        $accessToken->setIsExpired(true);
        $entityManager->persist($accessToken);
        $entityManager->flush();

        return $this->json([
            'status' => false,
            'message' => 'Token expired'
        ]);
    }
}