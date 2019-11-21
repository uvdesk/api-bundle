<?php

namespace Webkul\UVDesk\ApiBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webkul\UVDesk\ApiBundle\DependencyInjection\ApiExtension;

class UVDeskApiBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new ApiExtension();
    }

    public function build(ContainerBuilder $container)
    {
        parent::build($container);

    }
}