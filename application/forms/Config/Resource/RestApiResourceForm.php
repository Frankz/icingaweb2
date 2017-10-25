<?php
/* Icinga Web 2 | (c) 2017 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Config\Resource;

use ErrorException;
use Icinga\Web\Form;
use Icinga\Web\Form\Validator\RestApiUrlValidator;
use Icinga\Web\Url;
use Zend_Form_Element_Checkbox;

/**
 * Form class for adding/modifying ReST API resources
 */
class RestApiResourceForm extends Form
{
    /**
     * Not neccessarily present checkboxes
     *
     * @var string[]
     */
    protected $optionalCheckboxes = array(
        'force_creation',
        'tls_server_insecure',
        'tls_server_discover_rootca',
        'tls_server_accept_rootca',
        'tls_server_accept_cn'
    );

    /**
     * The next unused checkbox order
     *
     * @var int
     */
    protected $nextCheckboxOrder = -1;

    public function init()
    {
        $this->setName('form_config_resource_restapi');
    }

    public function createElements(array $formData)
    {
        $this->addElement(
            'text',
            'name',
            array(
                'required'      => true,
                'label'         => $this->translate('Resource Name'),
                'description'   => $this->translate('The unique name of this resource')
            )
        );

        $this->addElement(
            'text',
            'baseurl',
            array(
                'label'         => $this->translate('Base URL'),
                'description'   => $this->translate('http[s]://<HOST>[:<PORT>][/<BASE_LOCATION>]'),
                'required'      => true,
                'validators'    => array(new RestApiUrlValidator())
            )
        );

        $this->addElement(
            'text',
            'username',
            array(
                'label'         => $this->translate('Username'),
                'description'   => $this->translate(
                    'A user with access to the above URL via HTTP basic authentication'
                )
            )
        );

        $this->addElement(
            'password',
            'password',
            array(
                'label'         => $this->translate('Password'),
                'description'   => $this->translate('The above user\'s password')
            )
        );

        $tlsClientIdentities = array(
            // TODO
        );

        if (empty($tlsClientIdentities)) {
            $this->addElement(
                'note',
                'tls_client_identities_missing',
                array(
                    'ignore'        => true,
                    'label'         => $this->translate('TLS Client Identity'),
                    'description'   => $this->translate('TLS X509 client certificate with its private key (PEM)'),
                    'escape'        => false,
                    'value'         => sprintf(
                        $this->translate(
                            'There aren\'t any TLS client identities you could choose from, but you can %sadd some%s.'
                        ),
                        sprintf(
                            '<a data-base-target="_next" href="#" title="%s" class="highlighted">', // TODO
                            $this->translate('Add TLS client identity')
                        ),
                        '</a>'
                    )
                )
            );
        } else {
            $this->addElement(
                'select',
                'tls_client_identity',
                array(
                    'label'         => $this->translate('TLS Client Identity'),
                    'description'   => $this->translate('TLS X509 client certificate with its private key (PEM)'),
                    'multiOptions'  => array_merge(
                        array('' => $this->translate('(none)')),
                        $tlsClientIdentities
                    ),
                    'value'         => ''
                )
            );
        }

        return $this->ensureOnlyCheckboxes(array_intersect($this->optionalCheckboxes, array_keys($formData)));
    }

    /**
     * Ensure that only the given checkboxes are present
     *
     * @return $this
     */
    protected function ensureOnlyCheckboxes(array $checkboxes = array())
    {
        foreach (array_diff($this->optionalCheckboxes, $checkboxes) as $checkbox) {
            $this->removeElement($checkbox);
        }

        $this->nextCheckboxOrder = -1;

        foreach ($checkboxes as $checkbox) {
            $element = $this->getElement($checkbox);

            if ($element === null) {
                switch ($checkbox) {
                    case 'force_creation':
                        $this->addElement('checkbox', 'force_creation', array(
                            'order'         => ++$this->nextCheckboxOrder,
                            'ignore'        => true,
                            'label'         => $this->translate('Force Changes'),
                            'description'   => $this->translate(
                                'Check this box to enforce changes without connectivity validation'
                            )
                        ));
                        break;

                    case 'tls_server_insecure':
                        $this->addElement('checkbox', 'tls_server_insecure', array(
                            'order'         => ++$this->nextCheckboxOrder,
                            'label'         => $this->translate('Insecure Connection'),
                            'description'   => $this->translate(
                                'Don\'t validate the remote\'s TLS certificate chain at all'
                            )
                        ));
                        break;

                    case 'tls_server_discover_rootca':
                        $this->addElement('checkbox', 'tls_server_discover_rootca', array(
                            'order'         => ++$this->nextCheckboxOrder,
                            'ignore'        => true,
                            'label'         => $this->translate('Discover Root CA'),
                            'description'   => $this->translate(
                                'Discover the remote\'s TLS certificate\'s root CA'
                                    . ' (makes sense only in case of an isolated PKI)'
                            )
                        ));
                        break;

                    case 'tls_server_accept_rootca':
                        $this->addElement('checkbox', 'tls_server_accept_rootca', array(
                            'order'         => ++$this->nextCheckboxOrder,
                            'ignore'        => true,
                            'label'         => $this->translate('Accept the remote\'s root CA'),
                            'description'   => $this->translate('Trust the remote\'s TLS certificate\'s root CA')
                        ));
                        break;

                    case 'tls_server_accept_cn':
                        $this->addElement('checkbox', 'tls_server_accept_cn', array(
                            'order'         => ++$this->nextCheckboxOrder,
                            'ignore'        => true,
                            'label'         => $this->translate('Accept the remote\'s CN'),
                            'description'   => $this->translate('Accept the remote\'s TLS certificate\'s CN')
                        ));
                        break;
                }
            } else {
                $element->setOrder(++$this->nextCheckboxOrder);
            }
        }

        return $this;
    }

    public function isValid($formData)
    {
        if (! parent::isValid($formData)) {
            return false;
        }

        if ($this->isBoxChecked('force_creation')) {
            return true;
        }

        if (! $this->probeTcpConnection()) {
            $this->ensureOnlyCheckboxes(array('force_creation'));
            return false;
        }

        if (Url::fromPath($this->getValue('baseurl'))->getScheme() === 'https') {
            if (! $this->probeInsecureTlsConnection()) {
                $this->ensureOnlyCheckboxes(array('force_creation'));
                return false;
            }

            if ($this->isBoxChecked('tls_server_insecure')) {
                return true;
            }

            if ($this->isBoxChecked('tls_server_discover_rootca')) {
                $certs = $this->fetchServerTlsCertChain();
                if ($certs === false) {
                    return false;
                }

                if ($certs['leaf']['parsed']['subject']['CN'] === $certs['leaf']['parsed']['issuer']['CN']) {
                    $this->addError($this->translate('The remote didn\'t provide any non-self-signed TLS certificate'));
                    return false;
                }

                if (! isset($certs['root'])) {
                    $this->addError($this->translate('The remote didn\'t provide any root CA certificate'));
                    return false;
                }

                // TODO: remote TLS root CA review
            }

            if (! $this->probeSecureTlsConnection()) {
                $this->ensureOnlyCheckboxes(
                    array('force_creation', 'tls_server_insecure', 'tls_server_discover_rootca')
                );
                return false;
            }
        }

        return true;
    }

    protected function probeTcpConnection()
    {
        try {
            fclose(stream_socket_client('tcp://' . $this->getTcpEndpoint()));
        } catch (ErrorException $element) {
            $this->addError($element->getMessage());
            return false;
        }

        return true;
    }

    protected function probeInsecureTlsConnection()
    {
        try {
            fclose($this->createTlsStream(stream_context_create($this->includeTlsClientIdentity(array('ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false
            ))))));
        } catch (ErrorException $element) {
            $this->addError($element->getMessage());
            return false;
        }

        return true;
    }

    protected function probeSecureTlsConnection()
    {
        try {
            fclose($this->createTlsStream(stream_context_create($this->includeTlsClientIdentity(array()))));
        } catch (ErrorException $element) {
            $this->addError($element->getMessage());
            return false;
        }

        return true;
    }

    protected function includeTlsClientIdentity(array $contextOptions)
    {
        if ($this->getValue('tls_client_identity') !== null) {
            $contextOptions['ssl']['local_cert'] = null; // TODO
        }
        
        return $contextOptions;
    }

    protected function createTlsStream($context)
    {
        return stream_socket_client(
            'tls://' . $this->getTcpEndpoint(),
            $errno,
            $errstr,
            ini_get('default_socket_timeout'),
            STREAM_CLIENT_CONNECT,
            $context
        );
    }

    protected function getTcpEndpoint()
    {
        $baseurl = Url::fromPath($this->getValue('baseurl'));
        $port = $baseurl->getPort();

        return $baseurl->getHost() . ':' . ($port === null ? '443' : $port);
    }

    protected function isBoxChecked($name)
    {
        /** @var Zend_Form_Element_Checkbox $checkbox */
        $checkbox = $this->getElement($name);
        return $checkbox !== null && $checkbox->isChecked();
    }

    /**
     * Try to fetch the remote's TLS certificate chain
     *
     * @return array|false
     */
    protected function fetchServerTlsCertChain()
    {
        $context = stream_context_create($this->includeTlsClientIdentity(array('ssl' => array(
            'verify_peer'               => false,
            'verify_peer_name'          => false,
            'capture_peer_cert_chain'   => true
        ))));

        try {
            fclose($this->createTlsStream($context));
        } catch (ErrorException $e) {
            $this->addError($e->getMessage());
            return false;
        }

        $params = stream_context_get_params($context);
        $rawChain = $params['options']['ssl']['peer_certificate_chain'];
        $chain = array('leaf' => array('x509' => null));

        openssl_x509_export(reset($rawChain), $chain['leaf']['x509']);

        if (count($rawChain) > 1) {
            $chain['root'] = array('x509' => null);
            openssl_x509_export(end($rawChain), $chain['root']['x509']);
        }

        foreach ($chain as & $cert) {
            $cert['parsed'] = openssl_x509_parse($cert['x509']);
        }

        if (isset($chain['root'])
            && $chain['root']['parsed']['subject']['CN'] !== $chain['root']['parsed']['issuer']['CN']
        ) {
            unset($chain['root']);
        }

        return $chain;
    }
}