<?php
/**
 * 2015-2017 Divvit AB.
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
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
 
if (!defined('_PS_VERSION_')) {
    exit();
}

require_once _PS_MODULE_DIR_.'divvit/sql/DivvitQueryHelper.php';
require_once _PS_MODULE_DIR_.'divvit/sql/install.php';
require_once _PS_MODULE_DIR_.'divvit/sql/uninstall.php';

class Divvit extends Module
{
    protected $config_form = false;

    protected static $products = array();

    public static $TRACKER_URL;
    public static $APP_URL;
    public static $TAG_URL;

    public function __construct()
    {
        $this->name = 'divvit';
        $this->tab = 'analytics_stats';
        $this->version = '1.2.1';
        $this->author = 'Divvit AB';
        $this->need_instance = 1;
        $this->module_key = '2e236afa721b6106b1c1888cdd31de3c';
        $this->secure_key = Tools::encrypt($this->name);

        self::$TRACKER_URL = getenv('DIVVIT_TRACKER_URL') ?: 'https://tracker.divvit.com';
        self::$APP_URL = getenv('DIVVIT_APP_URL') ?: 'https://app.divvit.com';
        self::$TAG_URL = getenv('DIVVIT_TAG_URL') ?: 'https://tag.divvit.com';

        /*
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Divvit');
        $this->description = $this->l('Divvit tracking pixel integration for Prestashop.');

        $this->confirmUninstall = $this->l('');

        $this->ps_versions_compliancy = array(
            'min' => '1.5',
            // before 1.5.6.2 we cannot pass the current version as max, or the module will be rejected
            'max' => (strpos(_PS_VERSION_, '1.5') === 0 ? '1.7' : _PS_VERSION_),
        );
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update.
     */
    public function install()
    {
        DivvitInstallModule::install();

        $installResult = parent::install() && $this->registerHook('header') && $this->registerHook('backOfficeHeader')
            && $this->registerHook('displayHeader') && $this->registerHook('actionCartSave')
            && $this->registerHook('orderConfirmation') && $this->registerHook('moduleRoutes');
        if ($installResult) {
            try {
                DivvitQueryHelper::getDivvitAuthToken();
            } catch (Exception $e) {
                return false;
            }
        }

        return $installResult;
    }

    public function uninstall()
    {
        Configuration::deleteByName('DIVVIT_ACCESS_TOKEN');
        Configuration::deleteByName('DIVVIT_MERCHANT_ID');

        DivvitUninstallModule::uninstall();

        return parent::uninstall();
    }

    /**
     * Load the configuration form.
     */
    public function getContent()
    {
        $isSaved = false;
        $errorMessage = '';
        /*
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitDivvitModule')) == true) {
            try {
                $this->postProcess();
                $isSaved = true;
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
            }
        }
        switch (Shop::getContext()) {
            case 1:
                $issite = 1;
                break;
            default:
                $issite = 0;
                break;
        }
        DivvitQueryHelper::getDivvitAuthToken();
        $currency = $this->context->currency->iso_code;
        $url = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').'
        '.$this->context->shop->domain.$this->context->shop->physical_uri.$this->context->shop->virtual_uri;
        
        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('app_url', self::$APP_URL);
        $this->context->smarty->assign('email', $this->context->employee->email);
        $this->context->smarty->assign('firstname', $this->context->employee->firstname);
        $this->context->smarty->assign('lastname', $this->context->employee->lastname);
        $this->context->smarty->assign('url', $url);
        $this->context->smarty->assign('currency', $currency);
        $this->context->smarty->assign('timezone', Configuration::get('PS_TIMEZONE'));
        $this->context->smarty->assign('secure_key', $this->secure_key);
        $this->context->smarty->assign('divvit_access_token', Configuration::get('DIVVIT_ACCESS_TOKEN'));
        $this->context->smarty->assign('divvit_frontendId', Configuration::get('DIVVIT_MERCHANT_ID'));
        $this->context->smarty->assign('is_saved', $isSaved);
        $this->context->smarty->assign('error_message', $errorMessage);
        $this->context->smarty->assign('issite', $issite);
        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output;
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitDivvitModule';
        $adminModuleLink = $this->context->link->getAdminLink('AdminModules', false);
        $helper->currentIndex = $adminModuleLink.'&configure='
            .$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array(
            $this->getConfigForm(),
        ));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-user"></i>',
                        'desc' => $this->l('Enter a valid Divvit Frontend ID'),
                        'name' => 'DIVVIT_MERCHANT_ID',
                        'label' => $this->l('Frontend ID'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'DIVVIT_MERCHANT_ID' => Configuration::get('DIVVIT_MERCHANT_ID', ''),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
        DivvitQueryHelper::getDivvitAuthToken();
    }

    public function hookModuleRoutes()
    {
        return array(
            'divvit-orders' => array(
                'controller' => 'default',
                'rule' => 'divvitOrders',
                'keywords' => array(),
                'params' => array(
                    'fc' => 'module',
                    'module' => 'divvit',
                ),
            ),
        );
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function hookDisplayHeader()
    {
        //Save cookie of current customer to db
        if ($this->context->customer->id) {
            DivvitQueryHelper::saveCustomerCookie($this->context->customer->id);
        }
        $divvit_frontendId = Configuration::get('DIVVIT_MERCHANT_ID');
        $this->smarty->assign('DIVVIT_MERCHANT_ID', $divvit_frontendId[$this->context->shop->id]);
        $this->smarty->assign('DIVVIT_TAG_URL', self::$TAG_URL);
        $this->smarty->assign('DIVVIT_VERSION', $this->version);

        return $this->display(__FILE__, 'hookDisplayHeader.tpl');
    }

    public function hookActionCartSave()
    {
        if (!isset($this->context->cart)) {
            return;
        }

        if (!Tools::getIsset('id_product')) {
            return;
        }

        /*
         * start the tracking
         */
        $cookieDivvit = DivvitQueryHelper::getDivvitCookie();
        if (!$cookieDivvit) {
            return;
        }
        $divvit_frontendId = Configuration::get('DIVVIT_MERCHANT_ID');
        $tracking = self::$TRACKER_URL.'/track.js?i='
            .$divvit_frontendId[$this->context->shop->id].'&e=cart&v=1.0.0&uid='.$cookieDivvit.'';

        $metaInfo = '{"cartId":"'.$this->context->cart->id.'"';
        $metaInfo .= ',"products":[';

        $currency = new Currency($this->context->currency->id);
        $currency_code = $currency->iso_code;

        $tmpArray = array();
        foreach ($this->context->cart->getProducts() as $product) {
            $tmpArray[] = Tools::jsonEncode($this->buildProductArray($product, array(), $currency_code));
        }
        $metaInfo .= implode(',', $tmpArray);

        $metaInfo .= ']}';

        $tracking .= '&m='.urlencode($metaInfo);
        Tools::file_get_contents($tracking);
    }

    public function hookOrderConfirmation($params)
    {
        if (isset($params['objOrder'])) {
            //For PS 1.6v and below
            $order = $params['objOrder'];
        } else {
            //For PS 1.7v
            $order = $params['order'];
        }

        if (Validate::isLoadedObject($order) && $order->getCurrentState() != (int) Configuration::get('PS_OS_ERROR')) {
            if ($order->id_customer == $this->context->cookie->id_customer) {
                $order_products = array();
                $cart = new Cart($order->id_cart);
                $currency = new Currency($this->context->currency->id);
                $currency_code = $currency->iso_code;

                foreach ($cart->getProducts() as $order_product) {
                    $order_products[] = $this->buildProductArray($order_product, array(), $currency_code);
                }

                $order_details = array(
                    'id' => $order->id,
                    'total' => $order->total_paid,
                    'currency' => $currency_code,
                    'shipping' => $order->total_shipping_tax_incl,
                    'totalProductsNet' => $order->total_products,
                    'payment' => $order->payment,
                    'userMail' => $this->context->customer->email,
                    'userName' => $this->context->customer->firstname.' '.$this->context->customer->lastname,
                );

                // build the template
                $this->smarty->assign('ORDER_DETAILS', $order_details);
                $this->smarty->assign('ORDER_PRODUCTS', $order_products);
                $this->smarty->assign('TEMPX', $order);

                return $this->display(__FILE__, 'hookDisplayAfterOrderCreated.tpl');
            }
        }
    }

    public function buildProductArray($product, $extras, $currency_code = '')
    {
        $products = '';

        // product ID
        $product_id = 0;
        if (!empty($product['id_product'])) {
            $product_id = $product['id_product'];
        } else {
            if (!empty($product['id'])) {
                $product_id = $product['id'];
            }
        }

        // product QTY
        $product_qty = 1;
        if (isset($extras['qty'])) {
            $product_qty = $extras['qty'];
        } elseif (isset($product['cart_quantity'])) {
            $product_qty = $product['cart_quantity'];
        }

        // build product array
        $products = array(
            'id' => $product_id,
            'name' => $product['name'],
            'category' => $product['category'],
            'quantity' => $product_qty,
            'price' => number_format($product['price'], '2'),
            'currency' => $currency_code,
        );

        return $products;
    }
}
