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
*  @author 2020 kevin. <info@getkevin.eu>
*  @copyright kevin.
*  @license http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
*/

class KevinRedirectModuleFrontController extends ModuleFrontController
{
    /**
     * Process redirect request.
     */
    public function postProcess()
    {
        if (!$this->module->active) {
            Tools::redirect('index.php?controller=order&step=3');
        }

        $cart = $this->context->cart;
        $currency = new Currency($cart->id_currency);

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $cart_id = intval($cart->id);
        $payment_status = Configuration::get('KEVIN_ORDER_STATUS_STARTED');
        $module_name = $this->module->displayName;
        $message = null;
        $currency_id = $cart->id_currency;
        $secure_key = $customer->secure_key;

        $this->module->validateOrder($cart_id, $payment_status, $cart->getOrderTotal(), $module_name, $message, array(), $currency_id, false, $secure_key);

        $order_id = Order::getOrderByCartId($cart_id);

        $bank_id = Tools::getValue('id');
        $kevinPayment = $this->module->getClient()->payment();

        $creditor_name = $this->module->creditorName;
        $creditor_account = $this->module->creditorAccount;

        $redirect_preferred = Configuration::get('KEVIN_REDIRECT_PREFERRED');
        if ($redirect_preferred === false) {
            $redirect_preferred = 1;
        }

        $attr = [
            'redirectPreferred' => boolval($redirect_preferred),
            'Redirect-URL' => $this->context->link->getModuleLink('kevin', 'confirm', array(), true),
            'Webhook-URL' => $this->context->link->getModuleLink('kevin', 'webhook', array(), true),
            'endToEndId' => strval($order_id),
            'informationUnstructured' => sprintf($this->module->l('Order') . ' %s', $order_id),
            'currencyCode' => $currency->iso_code,
            'amount' => number_format($cart->getOrderTotal(), 2, '.', ''),
            'creditorName' => $creditor_name,
            'creditorAccount' => [
                'iban' => $creditor_account,
            ],
        ];

        if ($bank_id !== null) {
            $attr['bankId'] = $bank_id;
        }

        $response = $kevinPayment->initPayment($attr);

        Db::getInstance()->insert('kevin', array(
            'id_order' => (int)$order_id,
            'payment_id' => pSQL($response['id']),
            'ip_address' => pSQL(Tools::getRemoteAddr()),
        ));

        return Tools::redirect($response['confirmLink']);
    }

    /**
     * @param $message
     * @param bool $description
     * @throws PrestaShopException
     */
    protected function displayError($message, $description = false)
    {
        $value = '<a href="' . $this->context->link->getPageLink('order', null, null, 'step=3') . '">' . $this->module->l('Payment') . '</a>';
        $value .= '<span class="navigation-pipe">&gt;</span>' . $this->module->l('Error');
        $this->context->smarty->assign('path', $value);

        array_push($this->errors, $this->module->l($message), $description);

        return $this->setTemplate('error.tpl');
    }
}
