<?php

declare(strict_types=1);

namespace Photo\Controller\Factory;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Photo\Controller\AlbumAdminController;
use Psr\Container\ContainerInterface;

class AlbumAdminControllerFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null,
    ): AlbumAdminController {
        return new AlbumAdminController(
            $container->get('photo_service_admin'),
            $container->get('photo_service_album'),
        );
    }
}
