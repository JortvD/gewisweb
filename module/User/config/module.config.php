<?php

namespace User;

use User\Controller\{
    ApiAdminController,
    ApiAuthenticationController,
    ApiController,
    UserController,
};
use User\Controller\Factory\{
    ApiAdminControllerFactory,
    ApiAuthenticationControllerFactory,
    ApiControllerFactory,
    UserControllerFactory,
};
use Application\Extensions\Doctrine\AttributeDriver;

return [
    'router' => [
        'routes' => [
            'user' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/user',
                    'defaults' => [
                        'controller' => UserController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'default' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                        ],
                    ],
                    'login' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/login',
                        ],
                    ],
                    'logout' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/logout',
                            'defaults' => [
                                'action' => 'logout',
                            ],
                        ],
                    ],
                    'pinlogin' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/pinlogin',
                            'defaults' => [
                                'action' => 'pinLogin',
                            ],
                        ],
                    ],
                    'activate_reset' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/reset/:code',
                            'constraints' => [
                                'code' => '[a-zA-Z0-9]*',
                            ],
                            'defaults' => [
                                'code' => '',
                                'action' => 'activateReset',
                            ],
                        ],
                    ],
                    'activate' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/activate/:code',
                            'constraints' => [
                                'code' => '[a-zA-Z0-9]*',
                            ],
                            'defaults' => [
                                'code' => '',
                                'action' => 'activate',
                            ],
                        ],
                    ],
                ],
                'priority' => 100,
            ],
            'user_admin' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/admin/user',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'api' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/api',
                            'defaults' => [
                                'controller' => ApiAdminController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'remove' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/remove/:id',
                                    'constraints' => [
                                        'id' => '[0-9]+',
                                    ],
                                    'defaults' => [
                                        'action' => 'remove',
                                    ],
                                ],
                            ],
                            'default' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'priority' => 100,
            ],
            'user_token' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/token/:appId',
                    'defaults' => [
                        'controller' => ApiAuthenticationController::class,
                        'action' => 'token',
                    ],
                ],
                'priority' => 100,
            ],
            'validate_login' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/api/validateLogin',
                    'defaults' => [
                        'controller' => ApiController::class,
                        'action' => 'validate',
                    ],
                ],
                'priority' => 100,
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            ApiAdminController::class => ApiAdminControllerFactory::class,
            ApiAuthenticationController::class => ApiAuthenticationControllerFactory::class,
            ApiController::class => ApiControllerFactory::class,
            UserController::class => UserControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            'user' => __DIR__ . '/../view/',
        ],
        'template_map' => [
            'user/login' => __DIR__ . '/../view/partial/login.phtml',
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
