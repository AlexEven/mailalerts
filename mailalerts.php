<?php
/**
 * 2007-2015 PrestaShop
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
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2015 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_CAN_LOAD_FILES_'))
	exit;

include_once(dirname(__FILE__).'/MailAlert.php');

class MailAlerts extends Module
{
	private $html = '';

	private $merchant_mails;
	private $merchant_order;
	private $merchant_oos;
	private $customer_qty;
	private $merchant_coverage;
	private $product_coverage;

	const __MA_MAIL_DELIMITOR__ = "\n";

	public function __construct()
	{
		$this->name = 'mailalerts';
		$this->tab = 'administration';
		$this->version = '3.4.6';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;

		$this->controllers = array('account');

		$this->bootstrap = true;
		parent::__construct();

		if ($this->id)
			$this->init();

		$this->displayName = $this->l('Mail alerts');
		$this->description = $this->l('Sends e-mail notifications to customers and merchants.');
		$this->confirmUninstall = $this->l('Are you sure you want to delete all customer notifications?');
	}

	private function init()
	{
		$this->merchant_mails = str_replace(',', self::__MA_MAIL_DELIMITOR__, (string)Configuration::get('MA_MERCHANT_MAILS'));
		$this->merchant_order = (int)Configuration::get('MA_MERCHANT_ORDER');
		$this->merchant_oos = (int)Configuration::get('MA_MERCHANT_OOS');
		$this->customer_qty = (int)Configuration::get('MA_CUSTOMER_QTY');
		$this->merchant_coverage = (int)Configuration::getGlobalValue('MA_MERCHANT_COVERAGE');
		$this->product_coverage = (int)Configuration::getGlobalValue('MA_PRODUCT_COVERAGE');
	}

	public function install($delete_params = true)
	{
		if (!parent::install() ||
			!$this->registerHook('actionValidateOrder') ||
			!$this->registerHook('actionUpdateQuantity') ||
			!$this->registerHook('actionProductOutOfStock') ||
			!$this->registerHook('displayCustomerAccount') ||
			!$this->registerHook('displayMyAccountBlock') ||
			!$this->registerHook('actionProductDelete') ||
			!$this->registerHook('actionProductAttributeDelete') ||
			!$this->registerHook('actionProductAttributeUpdate') ||
			!$this->registerHook('actionProductCoverage') ||
			!$this->registerHook('displayHeader'))
			return false;

		if ($delete_params)
		{
			Configuration::updateValue('MA_MERCHANT_ORDER', 1);
			Configuration::updateValue('MA_MERCHANT_OOS', 1);
			Configuration::updateValue('MA_CUSTOMER_QTY', 1);
			Configuration::updateValue('MA_MERCHANT_MAILS', Configuration::get('PS_SHOP_EMAIL'));
			Configuration::updateValue('MA_LAST_QTIES', (int)Configuration::get('PS_LAST_QTIES'));
			Configuration::updateGlobalValue('MA_MERCHANT_COVERAGE', 0);
			Configuration::updateGlobalValue('MA_PRODUCT_COVERAGE', 0);

			$sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				(
					`id_customer` int(10) unsigned NOT NULL,
					`customer_email` varchar(128) NOT NULL,
					`id_product` int(10) unsigned NOT NULL,
					`id_product_attribute` int(10) unsigned NOT NULL,
					`id_shop` int(10) unsigned NOT NULL,
					`id_lang` int(10) unsigned NOT NULL,
					PRIMARY KEY  (`id_customer`,`customer_email`,`id_product`,`id_product_attribute`,`id_shop`)
				) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci';

			if (!Db::getInstance()->execute($sql))
				return false;
		}

		return true;
	}

	public function uninstall($delete_params = true)
	{
		if ($delete_params)
		{
			Configuration::deleteByName('MA_MERCHANT_ORDER');
			Configuration::deleteByName('MA_MERCHANT_OOS');
			Configuration::deleteByName('MA_CUSTOMER_QTY');
			Configuration::deleteByName('MA_MERCHANT_MAILS');
			Configuration::deleteByName('MA_LAST_QTIES');
			Configuration::deleteByName('MA_MERCHANT_COVERAGE');
			Configuration::deleteByName('MA_PRODUCT_COVERAGE');

			if (!Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.MailAlert::$definition['table']))
				return false;
		}

		return parent::uninstall();
	}

	public function reset()
	{
		if (!$this->uninstall(false))
			return false;
		if (!$this->install(false))
			return false;

		return true;
	}

	public function getContent()
	{
		$this->html = '';

		$this->postProcess();

		$this->html .= $this->renderForm();

		return $this->html;
	}

	private function postProcess()
	{
		$errors = array();

		if (Tools::isSubmit('submitMailAlert'))
		{
			if (!Configuration::updateValue('MA_CUSTOMER_QTY', (int)Tools::getValue('MA_CUSTOMER_QTY')))
				$errors[] = $this->l('Cannot update settings');
		}
		else if (Tools::isSubmit('submitMAMerchant'))
		{
			$emails = (string)Tools::getValue('MA_MERCHANT_MAILS');

			if (!$emails || empty($emails))
				$errors[] = $this->l('Please type one (or more) e-mail address');
			else
			{
				$emails = str_replace(',', self::__MA_MAIL_DELIMITOR__, $emails);
				$emails = explode(self::__MA_MAIL_DELIMITOR__, $emails);
				foreach ($emails as $k => $email)
				{
					$email = trim($email);
					if (!empty($email) && !Validate::isEmail($email))
					{
						$errors[] = $this->l('Invalid e-mail:').' '.Tools::safeOutput($email);
						break;
					}
					elseif (!empty($email) && count($email) > 0)
						$emails[$k] = $email;
					else
						unset($emails[$k]);
				}

				$emails = implode(self::__MA_MAIL_DELIMITOR__, $emails);

				if (!Configuration::updateValue('MA_MERCHANT_MAILS', (string)$emails))
					$errors[] = $this->l('Cannot update settings');
				elseif (!Configuration::updateValue('MA_MERCHANT_ORDER', (int)Tools::getValue('MA_MERCHANT_ORDER')))
					$errors[] = $this->l('Cannot update settings');
				elseif (!Configuration::updateValue('MA_MERCHANT_OOS', (int)Tools::getValue('MA_MERCHANT_OOS')))
					$errors[] = $this->l('Cannot update settings');
				elseif (!Configuration::updateValue('MA_LAST_QTIES', (int)Tools::getValue('MA_LAST_QTIES')))
					$errors[] = $this->l('Cannot update settings');
				elseif (!Configuration::updateGlobalValue('MA_MERCHANT_COVERAGE', (int)Tools::getValue('MA_MERCHANT_COVERAGE')))
					$errors[] = $this->l('Cannot update settings');
				elseif (!Configuration::updateGlobalValue('MA_PRODUCT_COVERAGE', (int)Tools::getValue('MA_PRODUCT_COVERAGE')))
					$errors[] = $this->l('Cannot update settings');
			}
		}

		if (count($errors) > 0)
			$this->html .= $this->displayError(implode('<br />', $errors));
		else
			$this->html .= $this->displayConfirmation($this->l('Settings updated successfully'));

		$this->init();
	}

	public function getAllMessages($id)
	{
		$messages = Db::getInstance()->executeS('
			SELECT `message`
			FROM `'._DB_PREFIX_.'message`
			WHERE `id_order` = '.(int)$id.'
			ORDER BY `id_message` ASC');
		$result = array();
		foreach ($messages as $message)
			$result[] = $message['message'];

		return implode('<br/>', $result);
	}

	public function hookActionValidateOrder($params)
	{
		if (!$this->merchant_order || empty($this->merchant_mails))
			return;

		// Getting differents vars
		$context = Context::getContext();
		$id_lang = (int)$context->language->id;
		$id_shop = (int)$context->shop->id;
		$currency = $params['currency'];
		$order = $params['order'];
		$customer = $params['customer'];
		$configuration = Configuration::getMultiple(
			array(
				'PS_SHOP_EMAIL',
				'PS_MAIL_METHOD',
				'PS_MAIL_SERVER',
				'PS_MAIL_USER',
				'PS_MAIL_PASSWD',
				'PS_SHOP_NAME',
				'PS_MAIL_COLOR'
			), $id_lang, null, $id_shop
		);
		$delivery = new Address((int)$order->id_address_delivery);
		$invoice = new Address((int)$order->id_address_invoice);
		$order_date_text = Tools::displayDate($order->date_add);
		$carrier = new Carrier((int)$order->id_carrier);
		$message = $this->getAllMessages($order->id);

		if (!$message || empty($message))
			$message = $this->l('No message');

		$items_table = '';

		$products = $params['order']->getProducts();
		$customized_datas = Product::getAllCustomizedDatas((int)$params['cart']->id);
		Product::addCustomizationPrice($products, $customized_datas);
		foreach ($products as $key => $product)
		{
			$unit_price = $product['product_price_wt'];

			$customization_text = '';
			if (isset($customized_datas[$product['product_id']][$product['product_attribute_id']]))
			{
				foreach ($customized_datas[$product['product_id']][$product['product_attribute_id']][$order->id_address_delivery] as $customization)
				{
					if (isset($customization['datas'][_CUSTOMIZE_TEXTFIELD_]))
						foreach ($customization['datas'][_CUSTOMIZE_TEXTFIELD_] as $text)
							$customization_text .= $text['name'].': '.$text['value'].'<br />';

					if (isset($customization['datas'][_CUSTOMIZE_FILE_]))
						$customization_text .= count($customization['datas'][_CUSTOMIZE_FILE_]).' '.$this->l('image(s)').'<br />';

					$customization_text .= '---<br />';
				}
				if (method_exists('Tools', 'rtrimString'))
					$customization_text = Tools::rtrimString($customization_text, '---<br />');
				else
					$customization_text = preg_replace('/---<br \/>$/', '', $customization_text);
			}

			$items_table .=
				'<tr style="background-color:'.($key % 2 ? '#DDE2E6' : '#EBECEE').';">
					<td style="padding:0.6em 0.4em;">'.$product['product_reference'].'</td>
					<td style="padding:0.6em 0.4em;">
						<strong>'
							.$product['product_name']
							.(isset($product['attributes_small']) ? ' '.$product['attributes_small'] : '')
							.(!empty($customization_text) ? '<br />'.$customization_text : '')
						.'</strong>
					</td>
					<td style="padding:0.6em 0.4em; text-align:right;">'.Tools::displayPrice($unit_price, $currency, false).'</td>
					<td style="padding:0.6em 0.4em; text-align:center;">'.(int)$product['product_quantity'].'</td>
					<td style="padding:0.6em 0.4em; text-align:right;">'
						.Tools::displayPrice(($unit_price * $product['product_quantity']), $currency, false)
					.'</td>
				</tr>';
		}
		foreach ($params['order']->getCartRules() as $discount)
		{
			$items_table .=
				'<tr style="background-color:#EBECEE;">
						<td colspan="4" style="padding:0.6em 0.4em; text-align:right;">'.$this->l('Voucher code:').' '.$discount['name'].'</td>
					<td style="padding:0.6em 0.4em; text-align:right;">-'.Tools::displayPrice($discount['value'], $currency, false).'</td>
			</tr>';
		}
		if ($delivery->id_state)
			$delivery_state = new State((int)$delivery->id_state);
		if ($invoice->id_state)
			$invoice_state = new State((int)$invoice->id_state);

		// Filling-in vars for email
		$template_vars = array(
			'{firstname}' => $customer->firstname,
			'{lastname}' => $customer->lastname,
			'{email}' => $customer->email,
			'{delivery_block_txt}' => MailAlert::getFormatedAddress($delivery, "\n"),
			'{invoice_block_txt}' => MailAlert::getFormatedAddress($invoice, "\n"),
			'{delivery_block_html}' => MailAlert::getFormatedAddress(
					$delivery, '<br />', array(
						'firstname' => '<span style="color:'.$configuration['PS_MAIL_COLOR'].'; font-weight:bold;">%s</span>',
						'lastname' => '<span style="color:'.$configuration['PS_MAIL_COLOR'].'; font-weight:bold;">%s</span>'
					)
				),
			'{invoice_block_html}' => MailAlert::getFormatedAddress(
					$invoice, '<br />', array(
						'firstname' => '<span style="color:'.$configuration['PS_MAIL_COLOR'].'; font-weight:bold;">%s</span>',
						'lastname' => '<span style="color:'.$configuration['PS_MAIL_COLOR'].'; font-weight:bold;">%s</span>'
					)
				),
			'{delivery_company}' => $delivery->company,
			'{delivery_firstname}' => $delivery->firstname,
			'{delivery_lastname}' => $delivery->lastname,
			'{delivery_address1}' => $delivery->address1,
			'{delivery_address2}' => $delivery->address2,
			'{delivery_city}' => $delivery->city,
			'{delivery_postal_code}' => $delivery->postcode,
			'{delivery_country}' => $delivery->country,
			'{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
			'{delivery_phone}' => $delivery->phone ? $delivery->phone : $delivery->phone_mobile,
			'{delivery_other}' => $delivery->other,
			'{invoice_company}' => $invoice->company,
			'{invoice_firstname}' => $invoice->firstname,
			'{invoice_lastname}' => $invoice->lastname,
			'{invoice_address2}' => $invoice->address2,
			'{invoice_address1}' => $invoice->address1,
			'{invoice_city}' => $invoice->city,
			'{invoice_postal_code}' => $invoice->postcode,
			'{invoice_country}' => $invoice->country,
			'{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
			'{invoice_phone}' => $invoice->phone ? $invoice->phone : $invoice->phone_mobile,
			'{invoice_other}' => $invoice->other,
			'{order_name}' => $order->reference,
			'{shop_name}' => $configuration['PS_SHOP_NAME'],
			'{date}' => $order_date_text,
			'{carrier}' => (($carrier->name == '0') ? $configuration['PS_SHOP_NAME'] : $carrier->name),
			'{payment}' => Tools::substr($order->payment, 0, 32),
			'{items}' => $items_table,
			'{total_paid}' => Tools::displayPrice($order->total_paid, $currency),
			'{total_products}' => Tools::displayPrice($order->getTotalProductsWithTaxes(), $currency),
			'{total_discounts}' => Tools::displayPrice($order->total_discounts, $currency),
			'{total_shipping}' => Tools::displayPrice($order->total_shipping, $currency),
			'{total_tax_paid}' => Tools::displayPrice(
					($order->total_products_wt - $order->total_products) + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl),
					$currency,
					false
				),
			'{total_wrapping}' => Tools::displayPrice($order->total_wrapping, $currency),
			'{currency}' => $currency->sign,
			'{message}' => $message
		);

		// Shop iso
		$iso = Language::getIsoById((int)Configuration::get('PS_LANG_DEFAULT'));

		// Send 1 email by merchant mail, because Mail::Send doesn't work with an array of recipients
		$merchant_mails = explode(self::__MA_MAIL_DELIMITOR__, $this->merchant_mails);
		foreach ($merchant_mails as $merchant_mail)
		{
			// Default language
			$mail_id_lang = $id_lang;
			$mail_iso = $iso;

			// Use the merchant lang if he exists as an employee
			$results = Db::getInstance()->executeS('
				SELECT `id_lang` FROM `'._DB_PREFIX_.'employee`
				WHERE `email` = \''.pSQL($merchant_mail).'\'
			');
			if ($results)
			{
				$user_iso = Language::getIsoById((int)$results[0]['id_lang']);
				if ($user_iso)
				{
					$mail_id_lang = (int)$results[0]['id_lang'];
					$mail_iso = $user_iso;
				}
			}

			$dir_mail = false;
			if (file_exists(dirname(__FILE__).'/mails/'.$mail_iso.'/new_order.txt') &&
				file_exists(dirname(__FILE__).'/mails/'.$mail_iso.'/new_order.html'))
				$dir_mail = dirname(__FILE__).'/mails/';

			if (file_exists(_PS_MAIL_DIR_.$mail_iso.'/new_order.txt') &&
				file_exists(_PS_MAIL_DIR_.$mail_iso.'/new_order.html'))
				$dir_mail = _PS_MAIL_DIR_;

			if ($dir_mail)
				Mail::Send(
					$mail_id_lang,
					'new_order',
					sprintf(Mail::l('New order : #%d - %s', $mail_id_lang), $order->id, $order->reference),
					$template_vars,
					$merchant_mail,
					null,
					$configuration['PS_SHOP_EMAIL'],
					$configuration['PS_SHOP_NAME'],
					null,
					null,
					$dir_mail,
					null,
					$id_shop
				);
		}
	}

	public function hookActionProductOutOfStock($params)
	{
		if (!$this->customer_qty ||
			!Configuration::get('PS_STOCK_MANAGEMENT') ||
			Product::isAvailableWhenOutOfStock($params['product']->out_of_stock))
			return;

		$context = Context::getContext();
		$id_product = (int)$params['product']->id;
		$id_product_attribute = 0;
		$id_customer = (int)$context->customer->id;

		if ((int)$context->customer->id <= 0)
			$this->context->smarty->assign('email', 1);
		elseif (MailAlert::customerHasNotification($id_customer, $id_product, $id_product_attribute, (int)$context->shop->id))
			return;

		$this->context->smarty->assign(
			array(
				'id_product' => $id_product,
				'id_product_attribute' => $id_product_attribute
			)
		);

		return $this->display(__FILE__, 'product.tpl');
	}

	public function hookActionUpdateQuantity($params)
	{
		$id_product = (int)$params['id_product'];
		$id_product_attribute = (int)$params['id_product_attribute'];

		$quantity = (int)$params['quantity'];
		$context = Context::getContext();
		$id_shop = (int)$context->shop->id;
		$id_lang = (int)$context->language->id;
		$product = new Product($id_product, false, $id_lang, $id_shop, $context);
		$product_has_attributes = $product->hasAttributes();
		$configuration = Configuration::getMultiple(
			array(
				'MA_LAST_QTIES',
				'PS_STOCK_MANAGEMENT',
				'PS_SHOP_EMAIL',
				'PS_SHOP_NAME'
			), null, null, $id_shop
		);
		$ma_last_qties = (int)$configuration['MA_LAST_QTIES'];

		$check_oos = ($product_has_attributes && $id_product_attribute) || (!$product_has_attributes && !$id_product_attribute);

		if ($check_oos &&
			$product->active == 1 &&
			(int)$quantity <= $ma_last_qties &&
			!(!$this->merchant_oos || empty($this->merchant_mails)) &&
			$configuration['PS_STOCK_MANAGEMENT'])
		{
			$iso = Language::getIsoById($id_lang);
			$product_name = Product::getProductName($id_product, $id_product_attribute, $id_lang);
			$template_vars = array(
				'{qty}' => $quantity,
				'{last_qty}' => $ma_last_qties,
				'{product}' => $product_name
			);

			// Do not send mail if multiples product are created / imported.
			if (!defined('PS_MASS_PRODUCT_CREATION') &&
				file_exists(dirname(__FILE__).'/mails/'.$iso.'/productoutofstock.txt') &&
				file_exists(dirname(__FILE__).'/mails/'.$iso.'/productoutofstock.html'))
			{
				// Send 1 email by merchant mail, because Mail::Send doesn't work with an array of recipients
				$merchant_mails = explode(self::__MA_MAIL_DELIMITOR__, $this->merchant_mails);
				foreach ($merchant_mails as $merchant_mail)
				{
					Mail::Send(
						$id_lang,
						'productoutofstock',
						Mail::l('Product out of stock', $id_lang),
						$template_vars,
						$merchant_mail,
						null,
						(string)$configuration['PS_SHOP_EMAIL'],
						(string)$configuration['PS_SHOP_NAME'],
						null,
						null,
						dirname(__FILE__).'/mails/',
						false,
						$id_shop
					);
				}
			}
		}

		if ($this->customer_qty && $quantity > 0)
			MailAlert::sendCustomerAlert((int)$product->id, (int)$params['id_product_attribute']);
	}

	public function hookActionProductAttributeUpdate($params)
	{
		$sql = '
			SELECT `id_product`, `quantity`
			FROM `'._DB_PREFIX_.'stock_available`
			WHERE `id_product_attribute` = '.(int)$params['id_product_attribute'];

		$result = Db::getInstance()->getRow($sql);

		if ($this->customer_qty && $result['quantity'] > 0)
			MailAlert::sendCustomerAlert((int)$result['id_product'], (int)$params['id_product_attribute']);
	}

	public function hookDisplayCustomerAccount()
	{
		return $this->customer_qty ? $this->display(__FILE__, 'my-account.tpl') : null;
	}

	public function hookDisplayMyAccountBlock($params)
	{
		return $this->hookDisplayCustomerAccount($params);
	}

	public function hookActionProductDelete($params)
	{
		$sql = '
			DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
			WHERE `id_product` = '.(int)$params['product']->id;

		Db::getInstance()->execute($sql);
	}

	public function hookActionAttributeDelete($params)
	{
		if ($params['deleteAllAttributes'])
			$sql = '
				DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				WHERE `id_product` = '.(int)$params['id_product'];
		else
			$sql = '
				DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				WHERE `id_product_attribute` = '.(int)$params['id_product_attribute'].'
				AND `id_product` = '.(int)$params['id_product'];

		Db::getInstance()->execute($sql);
	}

	public function hookActionProductCoverage($params)
	{
		// if not advanced stock management, nothing to do
		if (!Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT'))
			return;

		// retrieves informations
		$id_product = (int)$params['id_product'];
		$id_product_attribute = (int)$params['id_product_attribute'];
		$warehouse = $params['warehouse'];
		$product = new Product($id_product);

		if (!Validate::isLoadedObject($product))
			return;

		if (!$product->advanced_stock_management)
			return;

		// sets warehouse id to get the coverage
		if (!Validate::isLoadedObject($warehouse))
			$id_warehouse = 0;
		else
			$id_warehouse = (int)$warehouse->id;

		// coverage of the product
		$warning_coverage = (int)Configuration::getGlobalValue('MA_PRODUCT_COVERAGE');

		$coverage = StockManagerFactory::getManager()->getProductCoverage($id_product, $id_product_attribute, $warning_coverage, $id_warehouse);

		// if we need to send a notification
		if ($product->active == 1 &&
			($coverage < $warning_coverage) && !empty($this->merchant_mails) &&
			Configuration::getGlobalValue('MA_MERCHANT_COVERAGE'))
		{
			$context = Context::getContext();
			$id_lang = (int)$context->language->id;
			$id_shop = (int)$context->shop->id;
			$iso = Language::getIsoById($id_lang);
			$product_name = Product::getProductName($id_product, $id_product_attribute, $id_lang);
			$template_vars = array(
				'{current_coverage}' => $coverage,
				'{warning_coverage}' => $warning_coverage,
				'{product}' => pSQL($product_name)
			);

			if (file_exists(dirname(__FILE__).'/mails/'.$iso.'/productcoverage.txt') &&
				file_exists(dirname(__FILE__).'/mails/'.$iso.'/productcoverage.html'))
			{
				// Send 1 email by merchant mail, because Mail::Send doesn't work with an array of recipients
				$merchant_mails = explode(self::__MA_MAIL_DELIMITOR__, $this->merchant_mails);
				foreach ($merchant_mails as $merchant_mail)
				{
					Mail::Send(
						$id_lang,
						'productcoverage',
						Mail::l('Stock coverage', $id_lang),
						$template_vars,
						$merchant_mail,
						null,
						(string)Configuration::get('PS_SHOP_EMAIL'),
						(string)Configuration::get('PS_SHOP_NAME'),
						null,
						null,
						dirname(__FILE__).'/mails/',
						null,
						$id_shop
					);
				}
			}
		}
	}

	public function hookDisplayHeader()
	{
		$this->page_name = Dispatcher::getInstance()->getController();
		if (in_array($this->page_name, array('product', 'account')))
		{
			$this->context->controller->addJS($this->_path.'js/mailalerts.js');
			$this->context->controller->addCSS($this->_path.'css/mailalerts.css', 'all');
		}
	}

	public function renderForm()
	{
		$fields_form_1 = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Customer notifications'),
					'icon' => 'icon-cogs'
				),
				'input' => array(
					array(
						'type' => 'switch',
						'is_bool' => true, //retro compat 1.5
						'label' => $this->l('Product availability'),
						'name' => 'MA_CUSTOMER_QTY',
						'desc' => $this->l('Gives the customer the option of receiving a notification when an out-of-stock product is available again.'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
					'class' => 'btn btn-default pull-right',
					'name' => 'submitMailAlert',
				)
			),
		);

		$fields_form_2 = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Merchant notifications'),
					'icon' => 'icon-cogs'
				),
				'input' => array(
					array(
						'type' => 'switch',
						'is_bool' => true, //retro compat 1.5
						'label' => $this->l('New order'),
						'name' => 'MA_MERCHANT_ORDER',
						'desc' => $this->l('Receive a notification when an order is placed.'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'is_bool' => true, //retro compat 1.5
						'label' => $this->l('Out of stock'),
						'name' => 'MA_MERCHANT_OOS',
						'desc' => $this->l('Receive a notification if the available quantity of a product is below the following threshold.'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'text',
						'label' => $this->l('Threshold'),
						'name' => 'MA_LAST_QTIES',
						'class' => 'fixed-width-xs',
						'desc' => $this->l('Quantity for which a product is considered out of stock.'),
					),
					array(
						'type' => 'switch',
						'is_bool' => true, //retro compat 1.5
						'label' => $this->l('Coverage warning'),
						'name' => 'MA_MERCHANT_COVERAGE',
						'desc' => $this->l('Receive a notification when a product has insufficient coverage.'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'text',
						'label' => $this->l('Coverage'),
						'name' => 'MA_PRODUCT_COVERAGE',
						'class' => 'fixed-width-xs',
						'desc' => $this->l('Stock coverage, in days. Also, the stock coverage of a given product will be calculated based on this number.'),
					),
					array(
						'type' => 'textarea',
						'cols' => 36,
						'rows' => 4,
						'label' => $this->l('E-mail addresses'),
						'name' => 'MA_MERCHANT_MAILS',
						'desc' => $this->l('One e-mail address per line (e.g. bob@example.com).'),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
					'class' => 'btn btn-default pull-right',
					'name' => 'submitMAMerchant',
				)
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->module = $this;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitMailAlertConfiguration';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name
				.'&tab_module='.$this->tab
				.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form_1, $fields_form_2));
	}

	public function getConfigFieldsValues()
	{
		return array(
			'MA_CUSTOMER_QTY' => Tools::getValue('MA_CUSTOMER_QTY', Configuration::get('MA_CUSTOMER_QTY')),
			'MA_MERCHANT_ORDER' => Tools::getValue('MA_MERCHANT_ORDER', Configuration::get('MA_MERCHANT_ORDER')),
			'MA_MERCHANT_OOS' => Tools::getValue('MA_MERCHANT_OOS', Configuration::get('MA_MERCHANT_OOS')),
			'MA_LAST_QTIES' => Tools::getValue('MA_LAST_QTIES', Configuration::get('MA_LAST_QTIES')),
			'MA_MERCHANT_COVERAGE' => Tools::getValue('MA_MERCHANT_COVERAGE', Configuration::get('MA_MERCHANT_COVERAGE')),
			'MA_PRODUCT_COVERAGE' => Tools::getValue('MA_PRODUCT_COVERAGE', Configuration::get('MA_PRODUCT_COVERAGE')),
			'MA_MERCHANT_MAILS' => Tools::getValue('MA_MERCHANT_MAILS', Configuration::get('MA_MERCHANT_MAILS')),
		);
	}
}
