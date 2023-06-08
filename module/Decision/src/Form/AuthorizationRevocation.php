<?php

declare(strict_types=1);

namespace Decision\Form;

use Laminas\Form\Element\{
    Checkbox,
    Csrf,
    Submit,
};
use Laminas\Form\Form;
use Laminas\Mvc\I18n\Translator;
use Laminas\InputFilter\InputFilterProviderInterface;

class AuthorizationRevocation extends Form implements InputFilterProviderInterface
{
    public function __construct(Translator $translate)
    {
        parent::__construct();

        $this->add(
            [
                'name' => 'agree',
                'type' => Checkbox::class,
                'options' => [
                    'use_hidden_element' => false,
                ],
            ]
        );

        $this->add(
            [
                'name' => 'csrf_token',
                'type' => Csrf::class,
            ]
        );

        $this->add(
            [
                'name' => 'submit',
                'type' => Submit::class,
                'attributes' => [
                    'value' => $translate->translate('Revoke authorization'),
                ],
            ]
        );
    }

    /**
     * Input filter specification.
     *
     * @return array
     */
    public function getInputFilterSpecification(): array
    {
        return [
            'agree' => [
                'required' => true,
            ],
        ];
    }
}