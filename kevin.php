<?php
/*
* 2020 kevin.
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
*  @author 2020 Tammi <info@tammi.lt>
*  @copyright kevin.
*  @license http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Class Kevin
 */
class Kevin extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();
    protected $_warning;

    public $clientId;
    public $clientSecret;
    public $creditorName;
    public $creditorAccount;

    /**
     * Kevin constructor.
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'kevin';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6');
        $this->author = 'Tammi';
        $this->controllers = array('redirect', 'confirm', 'webhook');
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('kevin.');
        $this->description = $this->l('kevin. is a payment infrastructure company which offers payment initiation service in EU&EEA.');

        $this->confirmUninstall = $this->l('Are you sure you would like to uninstall?');

        $this->limited_countries = array('LT', 'LV', 'EE');
        $this->limited_currencies = array('EUR');

        $config = Configuration::getMultiple(array('KEVIN_CLIENT_ID', 'KEVIN_CLIENT_SECRET'));
        if (!empty($config['KEVIN_CLIENT_ID'])) {
            $this->clientId = $config['KEVIN_CLIENT_ID'];
        }
        if (!empty($config['KEVIN_CLIENT_SECRET'])) {
            $this->clientSecret = $config['KEVIN_CLIENT_SECRET'];
        }
        if (!$this->_warning && (!isset($this->clientId) || !isset($this->clientSecret))) {
            $this->_warning = $this->l('Client ID and Client Secret must be configured before using this module.');
        }

        $config = Configuration::getMultiple(array('KEVIN_CREDITOR_NAME', 'KEVIN_CREDITOR_ACCOUNT'));
        if (!empty($config['KEVIN_CREDITOR_NAME'])) {
            $this->creditorName = $config['KEVIN_CREDITOR_NAME'];
        }
        if (!empty($config['KEVIN_CREDITOR_ACCOUNT'])) {
            $this->creditorAccount = $config['KEVIN_CREDITOR_ACCOUNT'];
        }
        if (!$this->_warning && (!isset($this->creditorName) || !isset($this->creditorAccount))) {
            $this->_warning = $this->l('Creditor Name and Creditor Account must be configured before using this module.');
        }
    }

    /**
     * Process module installation.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (in_array($iso_code, $this->limited_countries) == false) {
            $this->_errors[] = $this->l('This module is not available in your country');

            return false;
        }

        include(dirname(__FILE__) . '/sql/install.php');

        $order_statuses = $this->getDefaultOrderStatuses();
        foreach ($order_statuses as $status_key => $status_config) {
            $this->addOrderStatus($status_key, $status_config);
        }

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('payment');
    }

    /**
     * Process module uninstall.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        $config_form_values = $this->getConfigFormValues();
        foreach (array_keys($config_form_values) as $config_key) {
            Configuration::deleteByName($config_key);
        }

        $order_statuses = $this->getDefaultOrderStatuses();
        foreach ($order_statuses as $status_key => $status_config) {
            $this->removeOrderStatus($status_key);
            Configuration::deleteByName($status_key);
        }

        include(dirname(__FILE__) . '/sql/uninstall.php');

        return parent::uninstall()
            && $this->unregisterHook('header')
            && $this->unregisterHook('backOfficeHeader')
            && $this->unregisterHook('payment');
    }


    /**
     * Load the configuration form.
     *
     * @return string
     * @throws SmartyException
     */
    public function getContent()
    {
        $is_submit = false;
        $buttons = ['submitKevinModule1', 'submitKevinModule2'];
        foreach ($buttons as $button) {
            if ((boolval(Tools::isSubmit($button))) === true) {
                $is_submit = true;
                break;
            }
        }

        if ($is_submit === true) {
            $this->postValidation();
            if (!count($this->_postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br>';
        }

        if (isset($this->_warning) && !count($this->_postErrors) && $is_submit === false) {
            $this->_html .= $this->displayError($this->_warning);
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $this->_html .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     *
     * @return string
     */
    protected function renderForm()
    {
        $client_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Client Details'),
                    'icon' => 'icon-key',
                ),
                'input' => array(
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'label' => $this->l('Client ID'),
                        'name' => 'KEVIN_CLIENT_ID',
                        'required' => true,
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'label' => $this->l('Client Secret'),
                        'name' => 'KEVIN_CLIENT_SECRET',
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitKevinModule1',
                ),
            ),
        );

        $creditor_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Creditor Details'),
                    'icon' => 'icon-user',
                ),
                'input' => array(
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'label' => $this->l('Creditor Name'),
                        'name' => 'KEVIN_CREDITOR_NAME',
                        'required' => true,
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'label' => $this->l('Creditor Account'),
                        'name' => 'KEVIN_CREDITOR_ACCOUNT',
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitKevinModule2',
                ),
            ),
        );

        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitKevinModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($client_form, $creditor_form));
    }

    /**
     * Set values for the inputs.
     *
     * @return array
     */
    protected function getConfigFormValues()
    {
        return array(
            'KEVIN_CLIENT_ID' => Tools::getValue('KEVIN_CLIENT_ID', Configuration::get('KEVIN_CLIENT_ID')),
            'KEVIN_CLIENT_SECRET' => Tools::getValue('KEVIN_CLIENT_SECRET', Configuration::get('KEVIN_CLIENT_SECRET')),
            'KEVIN_CREDITOR_NAME' => Tools::getValue('KEVIN_CREDITOR_NAME', Configuration::get('KEVIN_CREDITOR_NAME')),
            'KEVIN_CREDITOR_ACCOUNT' => Tools::getValue('KEVIN_CREDITOR_ACCOUNT', Configuration::get('KEVIN_CREDITOR_ACCOUNT')),
        );
    }

    /**
     * Validate form data.
     */
    protected function postValidation()
    {
        if (((bool)Tools::isSubmit('submitKevinModule1')) === true) {
            if (!Tools::getValue('KEVIN_CLIENT_ID')) {
                $this->_postErrors[] = $this->l('Client ID is required.');
            } elseif (!Tools::getValue('KEVIN_CLIENT_SECRET')) {
                $this->_postErrors[] = $this->l('Client Secret is required.');
            }
        }
        if (((bool)Tools::isSubmit('submitKevinModule2')) === true) {
            if (!Tools::getValue('KEVIN_CREDITOR_NAME')) {
                $this->_postErrors[] = $this->l('Creditor Name is required.');
            } elseif (!Tools::getValue('KEVIN_CREDITOR_ACCOUNT')) {
                $this->_postErrors[] = $this->l('Creditor Account is required.');
            }
        }
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $config_form_values = $this->getConfigFormValues();
        foreach (array_keys($config_form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Hook files for frontend.
     */
    public function hookHeader()
    {
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }

    /**
     * Hook files for frontend.
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addCSS($this->_path . '/views/css/back.css');
        }
    }

    /**
     * Display payment buttons.
     *
     * @param $params
     * @return bool|string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function hookPayment($params)
    {
        $currency_id = $params['cart']->id_currency;
        $currency = new Currency((int)$currency_id);

        if (in_array($currency->iso_code, $this->limited_currencies) == false) {

            return false;
        }

        if (!$this->validateClientCredentials()) {

            return false;
        }

        $banks = [];
        $bank_data = $this->getBanks();
        foreach ($bank_data as $bank_datum) {
            $banks[] = [
                'id' => $bank_datum['id'],
                'title' => $bank_datum['name'],
                'logo' => $bank_datum['imageUri'],
                'action' => $this->context->link->getModuleLink($this->name, 'redirect', array('id' => $bank_datum['id']), true),
            ];
        }

        $this->smarty->assign(array(
            'banks' => $banks,
            'module_dir' => $this->_path,
        ));

        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    /**
     * Return Kevin PHP Client instance.
     *
     * @return \Kevin\Client
     * @throws \Kevin\KevinException
     */
    public function getClient()
    {
        return new \Kevin\Client($this->clientId, $this->clientSecret);
    }

    /**
     * Return list of supported banks.
     *
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function getBanks()
    {
        try {
            $cart = $this->context->cart;
            $address = new Address($cart->id_address_invoice);
            $country = new Country($address->id_country);

            $kevinAuth = $this->getClient()->auth();
            $banks = $kevinAuth->getBanks(['countryCode' => $country->iso_code]);
        } catch (\Kevin\KevinException $exception) {

            return [];
        }

        return $banks['data'];
    }

    /**
     * Return list of default order statuses.
     *
     * @return array[]
     */
    protected function getDefaultOrderStatuses()
    {
        return array(
            'KEVIN_ORDER_STATUS_STARTED' => array(
                'name' => $this->l('Payment Started'),
                'color' => 'Lavender',
                'paid' => false,
            ),
            'KEVIN_ORDER_STATUS_PENDING' => array(
                'name' => $this->l('Payment Pending'),
                'color' => 'Orchid',
                'paid' => false,
            ),
            'KEVIN_ORDER_STATUS_COMPLETED' => array(
                'name' => $this->l('Payment Completed'),
                'color' => 'LimeGreen',
                'paid' => true,
            ),
            'KEVIN_ORDER_STATUS_FAILED' => array(
                'name' => $this->l('Payment Failed'),
                'color' => 'OrangeRed',
                'paid' => false,
            ),
        );
    }

    /**
     * Register module related order statuses.
     *
     * @param $statusKey
     * @param $statusConfig
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function addOrderStatus($statusKey, $statusConfig)
    {
        $orderState = new OrderState();
        $orderState->module_name = $this->name;
        $orderState->color = $statusConfig['color'];
        $orderState->send_email = true;
        $orderState->paid = $statusConfig['paid'];
        $orderState->name = array();
        $orderState->delivery = false;
        $orderState->logable = true;
        $orderState->hidden = false;
        foreach (Language::getLanguages() as $language) {
            $orderState->template[$language['id_lang']] = 'payment';
            $orderState->name[$language['id_lang']] = $statusConfig['name'];
        }
        $orderState->add();

        Configuration::updateValue($statusKey, $orderState->id);
    }

    /**
     * Unregister module related order statuses.
     *
     * @param $statusKey
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function removeOrderStatus($statusKey)
    {
        $order_state_id = Configuration::get($statusKey);
        if ($order_state_id) {
            $orderState = new OrderState($order_state_id);
            if (Validate::isLoadedObject($orderState)) {
                try {
                    $orderState->delete();
                } catch (\Exception $e) {
                }
            }
        }
    }

    /**
     * Validate client credentials.
     *
     * @return bool
     */
    public function validateClientCredentials()
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->creditorName) || empty($this->creditorAccount)) {

            return false;
        }

        return true;
    }
}