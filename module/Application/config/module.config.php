<?php

namespace Application;

use Application\Controller\IndexController;
use Application\Controller\Factory\IndexControllerFactory;
use Application\View\Helper\{
    BootstrapElementError,
    Breadcrumbs,
    FeaturedCompanyPackage,
    CompanyIdentity,
    LocalisedTextElement,
    LocaliseText,
};
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Laminas\Cache\Service\StorageCacheAbstractServiceFactory;
use Laminas\I18n\Translator\Resources;
use Laminas\Router\Http\{
    Literal,
    Segment,
};
use Laminas\Session\Config\ConfigInterface;
use Laminas\Session\Service\SessionConfigFactory;
use Memcached;
use Psr\Container\ContainerInterface;

return [
    'router' => [
        'routes' => [
            'lang' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/lang/:lang[/[:href]]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action' => 'lang',
                        'lang' => 'en',
                    ],
                    'constraints' => [
                        'lang' => 'nl|en',
                    ],
                ],
                'priority' => 100,
            ],
            'teapot' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/coffee',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action' => 'teapot',
                    ],
                ],
                'priority' => 100,
            ],
        ],
    ],
    'service_manager' => [
        'abstract_factories' => [
            StorageCacheAbstractServiceFactory::class,
        ],
        'factories' => [
            ConfigInterface::class => SessionConfigFactory::class,
            'doctrine.cache.my_memcached' => function () {
                $cache = new MemcachedCache();
                $memcached = new Memcached();
                $memcached->addServer('memcached', 11211);
                $cache->setMemcached($memcached);

                return $cache;
            },
        ],
    ],
    'translator' => [
        'locale' => 'en',
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
            ],
            // Translations for Laminas\Validator.
            [
                'type' => 'phparray',
                'base_dir' => Resources::getBasePath(),
                'pattern' => Resources::getPatternForValidator(),
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions' => true,
        'doctype' => 'HTML5',
        'not_found_template' => (APP_ENV === 'production' ? 'error/404' : 'error/debug/404'),
        'exception_template' => (APP_ENV === 'production' ? 'error/500' : 'error/debug/500'),
        'template_map' => [
            'layout/layout' => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/teapot' => __DIR__ . '/../view/error/418.phtml',
            'error/404' => __DIR__ . '/../view/error/404.phtml',
            'error/403' => __DIR__ . '/../view/error/403.phtml',
            'error/418' => __DIR__ . '/../view/error/418.phtml',
            'error/500' => __DIR__ . '/../view/error/500.phtml',
            'error/debug/404' => __DIR__ . '/../view/error/debug/404.phtml',
            'error/debug/403' => __DIR__ . '/../view/error/debug/403.phtml',
            'error/debug/500' => __DIR__ . '/../view/error/debug/500.phtml',
            'paginator/default' => __DIR__ . '/../view/partial/paginator.phtml',
        ],
        'template_path_stack' => [
            'laminas-developer-tools' => __DIR__ . '/../view',
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'featuredCompanyPackage' => function (ContainerInterface $container) {
                $companyService = $container->get('company_service_company');

                return new FeaturedCompanyPackage($companyService);
            },
            'breadcrumbs' => function () {
                return new Breadcrumbs();
            },
            'bootstrapElementError' => function () {
                return new BootstrapElementError();
            },
            'companyIdentity' => function (ContainerInterface $container) {
                return new CompanyIdentity(
                    $container->get('user_auth_companyUser_service'),
                );
            },
            'localisedTextElement' => function () {
                return new LocalisedTextElement();
            },
            'localiseText' => function () {
                return new LocaliseText();
            },
        ],
    ],
    'view_helper_config' => [
        'flashmessenger' => [
            'message_open_format' => '<div%s><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button><ul><li>',
            'message_close_string' => '</li></ul></div>',
            'message_separator_string' => '</li><li>',
        ],
    ],
    'doctrine' => [
        'driver' => [
            __NAMESPACE__ . '_driver' => [
                'class' => AttributeDriver::class,
                'paths' => [
                    __DIR__ . '/../src/Model/',
                ],
            ],
            'orm_default' => [
                'drivers' => [
                    __NAMESPACE__ . '\Model' => __NAMESPACE__ . '_driver',
                ],
            ],
        ],
    ],
];
