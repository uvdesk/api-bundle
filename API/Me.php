<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UVDeskService as Uvdesk;

class Me extends AbstractController
{
    public function loadCurrentAgentDetails(Request $request, Uvdesk $uvdesk)
    {
        $user = $this->getUser();
        $userInstance = $user->getCurrentInstance();

        $thumbnail = $uvdesk->generateCompleteLocalResourcePathUri($userInstance->getProfileImagePath() ?? $this->getParameter('assets_default_agent_profile_image_path'));
        $scopes = $uvdesk->getAvailableUserAccessScopes($user, $userInstance);

        return new JsonResponse([
            'success' => true, 
            'me' => [
                'id'        => $user->getId(), 
                'email'     => $user->getEmail(), 
                'name'      => trim(implode(' ', array_values(array_filter([$user->getFirstName(), $user->getLastName()])))), 
                'firstName' => $user->getFirstName(), 
                'lastName'  => $user->getLastName(), 
                'isEnabled' => $user->getIsEnabled(), 
                'thumbnail' => $thumbnail, 
            ], 
            'scopes' => $scopes,
        ]);
    }
}
