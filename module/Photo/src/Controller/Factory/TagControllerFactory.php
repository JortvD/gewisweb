<?php

namespace Photo\Controller\Factory;

use Psr\Container\ContainerInterface;
use Laminas\Mvc\I18n\Translator as MvcTranslator;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Photo\Controller\TagController;

class TagControllerFactory implements FactoryInterface
{
    /**
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     *
     * @return TagController
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null,
    ): TagController {
        return new TagController(
            $container->get('photo_service_acl'),
            $container->get(MvcTranslator::class),
            $container->get('photo_service_photo'),
        );
    }
}