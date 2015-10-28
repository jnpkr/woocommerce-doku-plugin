<?php

/*
Plugin Name: DOKU Payment Gateway
Plugin URI: http://www.doku.com
Description: DOKU Payment Gateway plugin extentions for woocommerce and Wordpress version 3.5.1
Version: 1.1
Author: DOKU
Author URI: http://www.doku.com

License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

//database
function install()
{
	global $wpdb;
	global $db_version;
	$db_version = "1.0";
 	$table_name = $wpdb->prefix . "dokuonecheckout";
	$sql = "
		CREATE TABLE $table_name (
		  trx_id int( 11 ) NOT NULL AUTO_INCREMENT,
		  ip_address VARCHAR( 16 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  process_type VARCHAR( 15 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  process_datetime DATETIME NULL,
		  doku_payment_datetime DATETIME NULL,
		  transidmerchant VARCHAR(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  amount DECIMAL( 20,2 ) NOT NULL DEFAULT '0',
		  notify_type VARCHAR( 1 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  response_code VARCHAR( 4 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  status_code VARCHAR( 4 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  result_msg VARCHAR( 20 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  reversal INT( 1 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT 0,
		  approval_code CHAR( 20 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  payment_channel VARCHAR( 2 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  payment_code VARCHAR( 20 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  bank_issuer VARCHAR( 100 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  creditcard VARCHAR( 16 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  words VARCHAR( 200 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  session_id VARCHAR( 48 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  verify_id VARCHAR( 30 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  verify_score INT( 3 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT 0,
		  verify_status VARCHAR( 10 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
		  check_status INT( 1 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT 0,
			count_check_status INT( 1 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT 0,
		  message TEXT COLLATE utf8_unicode_ci,
		  PRIMARY KEY (trx_id)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1

	";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);

	add_option('dokuonecheckout_db_version', $db_version);
}

function uninstall()
{
	delete_option('dokuonecheckout_db_version');
	global $wpdb;
	$table_name = $wpdb->prefix . "dokuonecheckout";
	$wpdb->query("DROP TABLE IF EXISTS $table_name");
}

register_activation_hook( __FILE__, 'install');
register_uninstall_hook(  __FILE__, 'uninstall');

add_action('plugins_loaded', 'woocommerce_gateway_dokuonecheckout_init', 0);

function woocommerce_gateway_dokuonecheckout_init()
{

		if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

		/**
		 * Localisation
		 */
		load_plugin_textdomain('wc-gateway-name', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

		/**
		 * Gateway class
		 */
		class WC_dokuonecheckout_Gateway extends WC_Payment_Gateway
		{
				public function __construct()
				{
						$this->id = 'dokuonecheckout';
						$this->ip_range = "103.10.129.";
						$this->method_title = 'dokuonecheckout';
						$this->has_fields = true;     // false

						$this->init_form_fields();
						$this->init_settings();

						$this->title       = $this->settings['name'];
						$this->description = 'Pay With DOKU.<br>
																	DOKU is an online payment that can process many kind of payment method, include Credit Card, ATM Transfer and DOKU Wallet.<br>
																	Check us at <a href="http://www.doku.com">http://www.doku.com</a>';

						if ( empty($this->settings['server_dest']) || $this->settings['server_dest'] == '0' || $this->settings['server_dest'] == 0 )
						{
								$this->mall_id     = trim($this->settings['mall_id_dev']);
								$this->shared_key  = trim($this->settings['shared_key_dev']);
								$this->chain       = trim($this->settings['chain_dev']);
								$this->url				 = "https://sandbox.doku.com/Suite/Receive";
						}
						else
						{
								$this->mall_id     = trim($this->settings['mall_id_prod']);
								$this->shared_key  = trim($this->settings['shared_key_prod']);
								$this->chain       = trim($this->settings['chain_prod']);
								$this->url				 = "https://pay.doku.com/Suite/Receive";
						}

						$pattern = "/([^a-zA-Z0-9]+)/";
						$result  = preg_match($pattern, $this->prefixid, $matches, PREG_OFFSET_CAPTURE);

						add_action('init', array(&$this, 'check_dokuonecheckout_response'));
						add_action('valid_dokuonecheckout_request', array(&$this, 'sucessfull_request'));
						add_action('woocommerce_receipt_dokuonecheckout', array(&$this, 'receipt_page'));

						if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
						{
								add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
						}
						else
						{
								add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
						}

						add_action( 'woocommerce_api_wc_dokuonecheckout_gateway', array( &$this, 'dokuonecheckout_callback' ) );
				}

			/**
			 * Initialisation form for Gateway Settings
			 */
				function init_form_fields()
				{

					$this->form_fields = array(
							'enabled' => array(
									'title' => __( 'Enable/Disable', 'woocommerce' ),
									'type' => 'checkbox',
									'label' => __( 'Enable DOKU Payment Gateway', 'woocommerce' ),
									'default' => 'yes'
							),
							'server_dest' => array(
									'title' => __( 'Server Destination', 'woocommerce' ),
									'type' => 'select',
									'description' => __( 'Choose server destination developmet or production.', 'woocommerce' ),
									'options' => array(
														'0' => __( 'Development', 'woocommerce' ),
														'1' => __( 'Production', 'woocommerce' )
									),
									'desc_tip' => true,
							),
							'mall_id_dev' => array(
									'title' => __( 'Mall ID Development', 'woocommerce' ),
									'type' => 'text',
									'description' => __( 'Input Mall ID Development get from DOKU.', 'woocommerce' ),
									'default' => '',
									'desc_tip' => true,
							),
							'shared_key_dev' => array(
									'title' => __( 'Shared Key Development', 'woocommerce' ),
									'type' => 'text',
									'description' => __( 'Input Shared Key Development get from DOKU.', 'woocommerce' ),
									'default' => '',
									'desc_tip' => true,
							),
							'chain_dev' => array(
									'title' => __('Chain Number Development', 'woocommerce'),
									'type' => 'text',
									'description' => __('Input Chain Number Development get from DOKU.', 'woocommerce'),
									'default' => 'NA',
									'desc_tip' => true,
							),
							'mall_id_prod' => array(
									'title' => __( 'Mall ID Production', 'woocommerce' ),
									'type' => 'text',
									'description' => __( 'Input Mall ID Production get from DOKU.', 'woocommerce' ),
									'default' => '',
									'desc_tip' => true,
							),
							'shared_key_prod' => array(
									'title' => __( 'Shared Key Production', 'woocommerce' ),
									'type' => 'text',
									'description' => __( 'Input Shared Key Production get from DOKU.', 'woocommerce' ),
									'default' => '',
									'desc_tip' => true,
							),
							'chain_prod' => array(
									'title' => __('Chain Number Production', 'woocommerce'),
									'type' => 'text',
									'description' => __('Input Chain Number Production get from DOKU.', 'woocommerce'),
									'default' => 'NA',
									'desc_tip' => true,
							),
							'edu' => array(
									'title' => __( 'EDU Service', 'woocommerce' ),
									'type' => 'checkbox',
									'description' => __( 'Are you using DOKU EDU Services? Unchecked if you unsure.', 'woocommerce' ),
									'default' => 'no'
							),
							'identify' => array(
									'title' => __( 'Identify', 'woocommerce' ),
									'type' => 'checkbox',
									'description' => __( 'Are you using Identify? Unchecked if you unsure.', 'woocommerce' ),
									'default' => 'no'
							),
							'name' => array(
									'title' => __('Payment Name : ', 'woocommerce'),
									'type' => 'text',
									'description' => __('Payment name to be displayed when checkout.', 'woocommerce'),
									'default' => 'DOKU Payment Gateway',
									'desc_tip' => true,
							),
					);

				}

				public function admin_options()
				{
						echo '<h2>'.__('DOKU Payment Gateway', 'woocommerce').'</h2>';
						echo '<p>' .__('DOKU is an online payment that can process many kind of payment method, include Credit Card, ATM Transfer and DOKU Wallet.<br>
														Check us at <a href="http://www.doku.com">http://www.doku.com</a>', 'woocommerce').'</p>';

						echo "<h3>dokuonecheckout Parameter</h3><br>\r\n";

						echo '<table class="form-table">';
						$this->generate_settings_html();
						echo '</table>';

						// URL
						$myserverpath = explode ( "/", $_SERVER['PHP_SELF'] );
						if ( $myserverpath[1] <> 'admin' && $myserverpath[1] <> 'wp-admin' )
						{
								$serverpath = '/' . $myserverpath[1];
						}
						else
						{
								$serverpath = '';
						}

						if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443)
						{
								$myserverprotocol = "https";
						}
						else
						{
								$myserverprotocol = "http";
						}

						$myservername = $_SERVER['SERVER_NAME'] . $serverpath;

						$mainurl =  $myserverprotocol.'://'.$myservername;

						echo "<h3>URL to put at DOKU Server</h3><br>\r\n";
						echo "<table>\r\n";
						echo "<tr><td width=\"100\">Verify URL</td><td width=\"3\">:</td><td>$mainurl/?wc-api=wc_dokuonecheckout_gateway&task=identify</td></tr>\r\n";
						echo "<tr><td>Notify URL</td><td>:</td><td>$mainurl/?wc-api=wc_dokuonecheckout_gateway&task=notify</td></tr>\r\n";
						echo "<tr><td>Redirect URL</td><td>:</td><td>$mainurl/?wc-api=wc_dokuonecheckout_gateway&task=redirect</td></tr>\r\n";
						echo "<tr><td>EDU Review URL</td><td>:</td><td>$mainurl/?wc-api=wc_dokuonecheckout_gateway&task=edureview</td></tr>\r\n";
						echo "</table>";

				}

				/**
				* Generate form
				*
				* @param mixed $order_id
				* @return string
				*/

				public function generate_dokuonecheckout_form($order_id)
				{

						global $woocommerce;
						global $wpdb;
						static $basket;

						$order = new WC_Order($order_id);
						$counter = 0;

						foreach($order->get_items() as $item)
						{
								$BASKET = $basket.$item['name'].','.$order->get_item_subtotal($item).','.$item['qty'].','.$order->get_line_subtotal($item).';';
						}

						$BASKET = "";

						// Order Items
						if( sizeof( $order->get_items() ) > 0 )
						{
								foreach( $order->get_items() as $item )
								{
										$BASKET .= $item['name'] . "," . number_format($order->get_item_subtotal($item), 2, '.', '') . "," . $item['qty'] . "," . number_format($order->get_item_subtotal($item)*$item['qty'], 2, '.', '') . ";";
								}
						}

						// Shipping Fee
						if( $order->order_shipping > 0 )
						{
								$BASKET .= "Shipping Fee," . number_format($order->order_shipping, 2, '.', '') . ",1," . number_format($order->order_shipping, 2, '.', '') . ";";
						}

						// Tax
						if( $order->get_total_tax() > 0 )
						{
								$BASKET .= "Tax," . $order->get_total_tax() . ",1," . $order->get_total_tax() . ";";
						}

						// Fees
						if ( sizeof( $order->get_fees() ) > 0 )
						{
								$fee_counter = 0;
								foreach ( $order->get_fees() as $item )
								{
										$fee_counter++;
										$BASKET .= "Fee Item," . $item['line_total'] . ",1," . $item['line_total'] . ";";
								}
						}

						$BASKET = preg_replace("/([^a-zA-Z0-9.\-,=:;&% ]+)/", " ", $BASKET);

						$MALL_ID             = trim($this->mall_id);
						$SHARED_KEY          = trim($this->shared_key);
						$CHAIN               = trim($this->chain);
						$URL                 = $this->url;
						$CURRENCY            = 360;
						$TRANSIDMERCHANT     = $order_id;
						$NAME                = trim($order->billing_first_name . " " . $order->billing_last_name);
						$EMAIL               = trim($order->billing_email);
						$ADDRESS             = trim($order->billing_address_1 . " " . $order->billing_address_2);
						$CITY                = trim($order->billing_city);
						$ZIPCODE             = trim($order->billing_postcode);
						$STATE               = trim($order->billing_city);
						$REQUEST_DATETIME    = date("YmdHis");
						$IP_ADDRESS          = $this->getipaddress();
						$PROCESS_DATETIME    = date("Y-m-d H:i:s");
						$PROCESS_TYPE        = "REQUEST";
						$AMOUNT              = number_format($order->order_total, 2, '.', '');
						$PHONE               = trim($order->billing_phone);
						$PAYMENT_CHANNEL     = "";
						$SESSION_ID          = COOKIEHASH;
						$WORDS               = sha1(trim($AMOUNT).
																				trim($MALL_ID).
																				trim($SHARED_KEY).
																				trim($TRANSIDMERCHANT));

						$dokuonecheckout_args = array(
							'MALLID'           => $MALL_ID,
							'CHAINMERCHANT'    => $CHAIN,
							'AMOUNT'           => $AMOUNT,
							'PURCHASEAMOUNT'   => $AMOUNT,
							'TRANSIDMERCHANT'  => $TRANSIDMERCHANT,
							'WORDS'            => $WORDS,
							'REQUESTDATETIME'  => $REQUEST_DATETIME,
							'CURRENCY'         => $CURRENCY,
							'PURCHASECURRENCY' => $CURRENCY,
							'SESSIONID'        => $SESSION_ID,
							'PAYMENTCHANNEL'   => $PAYMENT_CHANNEL,
							'NAME'             => $NAME,
							'EMAIL'            => $EMAIL,
							'HOMEPHONE'        => $PHONE,
							'MOBILEPHONE'      => $PHONE,
							'BASKET'           => $BASKET,
							'ADDRESS'          => $ADDRESS,
							'CITY'             => $CITY,
							'STATE'            => $STATE,
							'ZIPCODE'          => $ZIPCODE
						);

						$trx['ip_address']          = $IP_ADDRESS;
						$trx['process_type']        = $PROCESS_TYPE;
						$trx['process_datetime']    = $PROCESS_DATETIME;
						$trx['transidmerchant']     = $TRANSIDMERCHANT;
						$trx['amount']              = $AMOUNT;
						$trx['session_id']          = $SESSION_ID;
						$trx['words']               = $WORDS;
						$trx['message']             = "Transaction request start";

						# Insert transaction request to table dokuonecheckout
						$this->add_dokuonecheckout($trx);

						// Form
						$dokuonecheckout_args_array = array();
						foreach($dokuonecheckout_args as $key => $value)
						{
								$dokuonecheckout_args_array[] = "<input type='hidden' name='$key' value='$value' />";
						}

						return '<form action="'.$URL.'" method="post" id="dokuonecheckout_payment_form">'.
										implode(" \r\n", $dokuonecheckout_args_array).
										'<input type="submit" class="button-alt" id="submit_dokuonecheckout_payment_form" value="'.__('Pay via DOKU', 'woocommerce').'" />
										<!--
										<a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'woocommerce').'</a>
										-->

										<script type="text/javascript">
										jQuery(function(){
										jQuery("body").block(
										{
												message: "<img src=\"'.$woocommerce->plugin_url().'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to dokuonecheckout to make payment.', 'woocommerce').'",
												overlayCSS:
										{
										background: "#fff",
										opacity: 0.6
										},
										css: {
													padding:        20,
													textAlign:      "center",
													color:          "#555",
													border:         "3px solid #aaa",
													backgroundColor:"#fff",
													cursor:         "wait",
													lineHeight:     "32px"
												}
										});
										jQuery("#submit_dokuonecheckout_payment_form").click();});
										</script>
										</form>';

				}

				public function process_payment($order_id)
				{
						global $woocommerce;
						$order = new WC_Order($order_id);
						return array(
								'result' => 'success',
								'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
						);
				}

				public function receipt_page($order)
				{
						echo $this->generate_dokuonecheckout_form($order);
				}

				function getServerConfig()
				{
						if ( empty($this->settings['server_dest']) || $this->settings['server_dest'] == '0' || $this->settings['server_dest'] == 0 )
						{
								$MALL_ID    = trim($this->settings['mall_id_dev']);
								$SHARED_KEY = trim($this->settings['shared_key_dev']);
								$CHAIN      = trim($this->settings['chain_dev']);
								$URL_CHECK  = "https://sandbox.doku.com/Suite/CheckStatus";
						}
						else
						{
								$MALL_ID    = trim($this->settings['mall_id_prod']);
								$SHARED_KEY = trim($this->settings['shared_key_prod']);
								$CHAIN      = trim($this->settings['chain_prod']);
								$URL_CHECK  = "https://pay.doku.com/Suite/CheckStatus";
						}

						$USE_EDU      = trim($this->settings['edu']);
						$USE_IDENTIFY = trim($this->settings['identify']);

						$config = array( "MALL_ID"      => $MALL_ID,
														 "SHARED_KEY"   => $SHARED_KEY,
														 "CHAIN"        => $CHAIN,
														 "USE_EDU"      => $USE_EDU,
														 "USE_IDENTIFY" => $USE_IDENTIFY,
                             "URL_CHECK"    => $URL_CHECK );

						return $config;
				}

				private function getipaddress()
				{
						if (!empty($_SERVER['HTTP_CLIENT_IP']))
						{
							$ip=$_SERVER['HTTP_CLIENT_IP'];
						}
						elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
						{
							$ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
						}
						else
						{
							$ip=$_SERVER['REMOTE_ADDR'];
						}

						return $ip;
				}

				private function checkTrx($trx, $process='REQUEST', $result_msg='')
				{
						global $wpdb;

						if ( $result_msg == "PENDING" ) return 0;

						$db_prefix = $wpdb->prefix;

						$check_result_msg = "";
						if ( !empty($result_msg) )
						{
							$check_result_msg = " AND result_msg = '$result_msg'";
						}

						$wpdb->get_results("SELECT * FROM ".$db_prefix."dokuonecheckout" .
															 " WHERE process_type = '$process'" .
															 $check_result_msg.
															 " AND transidmerchant = '" . $trx['transidmerchant'] . "'" .
															 " AND amount = '". $trx['amount'] . "'".
															 " AND session_id = '". $trx['session_id'] . "'" );

						return $wpdb->num_rows;
				}

				private function add_dokuonecheckout($datainsert)
				{
						global $wpdb;

						$SQL = "";

						foreach ( $datainsert as $field_name=>$field_data )
						{
								$SQL .= " $field_name = '$field_data',";
						}
						$SQL = substr( $SQL, 0, -1 );

						$wpdb->query("INSERT INTO ".$wpdb->prefix."dokuonecheckout SET $SQL");
				}

				private function getCheckStatusList($trx='')
				{
						global $wpdb;

						$db_prefix = $wpdb->prefix;

						$query = "";
						if ( !empty($trx) )
						{
								$query  = " AND transidmerchant = '".$trx['transidmerchant']."'";
								$query .= " AND amount = '". $trx['amount'] . "'";
								$query .= " AND session_id = '". $trx['session_id'] . "'";
						}
						else
						{
								$query  = " AND check_status = 0";
						}

						$result = $wpdb->get_row("SELECT * FROM ".$db_prefix."dokuonecheckout" .
													           " WHERE process_type = 'REQUEST'" .
													           $query.
													           " AND count_check_status < 3" );

						if ( $wpdb->num_rows > 0 )
						{
								return $result;
						}
						else
						{
								return 0;
						}
				}

				private function updateCountCheckStatusTrx($trx)
				{
						global $wpdb;

						$db_prefix = $wpdb->prefix;

						$wpdb->get_results("UPDATE ".$db_prefix."dokuonecheckout" .
															 " SET count_check_status = count_check_status + 1,".
															 " check_status = 0".
															 " WHERE process_type = 'REQUEST'" .
															 " AND transidmerchant = '" . $trx['transidmerchant'] . "'" .
															 " AND amount = '". $trx['amount'] . "'".
															 " AND session_id = '". $trx['session_id'] . "'" );
				}

				private function doku_check_status($transaction)
				{
						$config = $this->getServerConfig();
						$result = $this->getCheckStatusList($transaction);

						if ( $result == 0 )
						{
								return "FAILED";
						}

						$trx     = $result;

						$words   = sha1( trim($config['MALL_ID']).
																		 trim($config['SHARED_KEY']).
																		 trim($trx->transidmerchant) );

						$data = "MALLID=".$config['MALL_ID']."&CHAINMERCHANT=".$config['CHAIN']."&TRANSIDMERCHANT=".$trx->transidmerchant."&SESSIONID=".$trx->session_id."&PAYMENTCHANNEL=&WORDS=".$words;

						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $config['URL_CHECK']);
						curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
						curl_setopt($ch, CURLOPT_HEADER, false);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
						curl_setopt($ch, CURLOPT_POST, true);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
						$output = curl_exec($ch);
						$curl_errno = curl_errno($ch);
						$curl_error = curl_error($ch);
						curl_close($ch);

						if ($curl_errno > 0)
						{
								#return "Stop : Connection Error";
						}

						libxml_use_internal_errors(true);
						$xml = simplexml_load_string($output);

						if ( !$xml )
						{
								$this->updateCountCheckStatusTrx($transaction);
						}
						else
						{
								$trx = array();
								$trx['ip_address']            = $this->getipaddress();
								$trx['process_type']          = "CHECKSTATUS";
								$trx['process_datetime']      = date("Y-m-d H:i:s");
								$trx['transidmerchant']       = (string) $xml->TRANSIDMERCHANT;
								$trx['amount']                = (string) $xml->AMOUNT;
								$trx['notify_type']           = (string) $xml->STATUSTYPE;
								$trx['response_code']         = (string) $xml->RESPONSECODE;
								$trx['result_msg']            = (string) $xml->RESULTMSG;
								$trx['approval_code']         = (string) $xml->APPROVALCODE;
								$trx['payment_channel']       = (string) $xml->PAYMENTCHANNEL;
								$trx['payment_code']          = (string) $xml->PAYMENTCODE;
								$trx['words']                 = (string) $xml->WORDS;
								$trx['session_id']            = (string) $xml->SESSIONID;
								$trx['bank_issuer']           = (string) $xml->BANK;
								$trx['creditcard']            = (string) $xml->MCN;
								$trx['verify_id']             = (string) $xml->VERIFYID;
								$trx['verify_score']          = (int) $xml->VERIFYSCORE;
								$trx['verify_status']         = (string) $xml->VERIFYSTATUS;

								# Insert transaction check status to table onecheckout
								$this->add_dokuonecheckout($trx);

								if ( $trx['payment_channel'] <> '01'  )
								{
										return "NOT SUPPORT";
								}

								return $xml->RESULTMSG;
						}
				}

				function clear_cart()
				{
						add_action( 'init', 'woocommerce_clear_cart_url' );
						global $woocommerce;

						$woocommerce->cart->empty_cart();
				}

				function dokuonecheckout_callback()
				{
						require_once(dirname(__FILE__) . "/dokuonecheckout.pages.inc");
						die;
				}

		}

		/**
		* Add the Gateway to WooCommerce
		**/
		function woocommerce_add_gateway_dokuonecheckout_gateway($methods)
		{
				$methods[] = 'WC_dokuonecheckout_Gateway';
				return $methods;
		}

		add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_dokuonecheckout_gateway' );

}

?>
