<?php
require_once dirname(__FILE__).'/../Helper/Crypto.php';

class Ezipay_Ezipayments_PaymentController extends Mage_Core_Controller_Front_Action
{
    const LOG_FILE = 'ezipay.log';
    const EZIPAY_AU_CURRENCY_CODE = 'AUD';
    const EZIPAY_AU_COUNTRY_CODE = 'AU';
    const EZIPAY_NZ_CURRENCY_CODE = 'NZD';
    const EZIPAY_NZ_COUNTRY_CODE = 'NZ';

    /**
     * GET: /ezipayments/payment/start
     *
     * Begin processing payment via ezipay
     */
    public function startAction()
    {
        if($this->validateQuote()) {
            try {
                $order = $this->getLastRealOrder();
                $payload = $this->getPayload($order);

                //Mage_Sales_Model_Order::setState($state, $status=false, $comment='', $isCustomerNotified=false)
                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, 'Certegy Ezi-Pay authorisation underway.');
                $order->setStatus(Ezipay_Ezipayments_Helper_OrderStatus::STATUS_PENDING_PAYMENT);
                $order->save();

                $this->postToCheckout(Ezipay_Ezipayments_Helper_Data::getCheckoutUrl(), $payload);
            } catch (Exception $ex) {
                Mage::logException($ex);
                Mage::log('An exception was encountered in ezipayments/paymentcontroller: ' . $ex->getMessage(), Zend_Log::ERR, self::LOG_FILE);
                Mage::log($ex->getTraceAsString(), Zend_Log::ERR, self::LOG_FILE);
                $this->getCheckoutSession()->addError($this->__('Unable to start Certegy Ezi-Pay Checkout.'));
            }
        } else {
            $this->restoreCart($this->getLastRealOrder());
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * GET: /ezipayments/payment/cancel
     * Cancel an order given an order id
     */
    public function cancelAction()
    {
        $orderId = $this->getRequest()->get('orderId');
        $order =  $this->getOrderById($orderId);

        if ($order && $order->getId()) {
            $cancel_signature_query = ["orderId"=>$orderId, "amount"=>$order->getTotalDue(), "email"=>$order->getData('customer_email'), "firstname"=>$order->getCustomerFirstname(), "lastname"=>$order->getCustomerLastname()];
            $cancel_signature = Ezipay_Ezipayments_Helper_Crypto::generateSignature($cancel_signature_query, $this->getApiKey());
            $signatureValid = ($this->getRequest()->get('signature') == $cancel_signature);
            if(!$signatureValid) {
                Mage::log('Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE);
                $this->_redirect('checkout/onepage/error', array('_secure'=> false));
                return;
            }
            Mage::log(
                'Requested order cancellation by customer. OrderId: ' . $order->getIncrementId(),
                Zend_Log::DEBUG,
                self::LOG_FILE
            );
            $this->cancelOrder($order);
            $this->restoreCart($order);
            $order->save();
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * GET: ezipayments/payment/complete
     *
     * callback - ezipay calls this once the payment process has been completed.
     */
    public function completeAction() {
        $isValid = Ezipay_Ezipayments_Helper_Crypto::isValidSignature($this->getRequest()->getParams(), $this->getApiKey());
        $result = $this->getRequest()->get("x_result");
        $orderId = $this->getRequest()->get("x_reference");
        $transactionId = $this->getRequest()->get("x_gateway_reference");

        if(!$isValid) {
            Mage::log('Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        if(!$orderId) {
            Mage::log("Certegy Ezi-Pay returned a null order id. This may indicate an issue with the Certegy Ezi-Pay payment gateway.", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $order = $this->getOrderById($orderId);

        if(!$order) {
            Mage::log("Certegy Ezi-Pay returned an id for an order that could not be retrieved: $orderId", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        // ensure that we have a Mage_Sales_Model_Order
        if (get_class($order) !== 'Mage_Sales_Model_Order') {
            Mage::log("The instance of order returned is an unexpected type.", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sales/order');

        try {
            $write->beginTransaction();

            $select = $write->select()
                ->forUpdate()
                ->from(array('t' => $table),
                    array('state'))
                ->where('increment_id = ?', $orderId);

            $state = $write->fetchOne($select);
            if ($state === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $whereQuery = array('increment_id = ?' => $orderId);

                if ($result == "completed")
                    $dataQuery = array('state' => Mage_Sales_Model_Order::STATE_PROCESSING);
                else
                    $dataQuery = array('state' => Mage_Sales_Model_Order::STATE_CANCELED);

                $write->update($table, $dataQuery, $whereQuery);
            } else {
                $write->commit();

                if ($result == "completed")
                    $this->_redirect('checkout/onepage/success', array('_secure'=> false));
                else
                    $this->_redirect('checkout/onepage/failure', array('_secure'=> false));

                return;
            }

            $write->commit();
        } catch (Exception $e) {
            $write->rollback();
            Mage::log("Transaction failed. Order status not updated", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $order = $this->getOrderById($orderId);
        $isFromAsyncCallback=(strtoupper($this->getRequest()->getMethod()=="POST"))? true:false; 

        if ($result == "completed") {
            $orderState = Mage_Sales_Model_Order::STATE_PROCESSING;
            $orderStatus = Mage::getStoreConfig('payment/ezipayments/ezipay_approved_order_status');
            $emailCustomer = Mage::getStoreConfig('payment/ezipayments/email_customer');
            if (!$this->statusExists($orderStatus)) {
                $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
            }

            $order->setState($orderState, $orderStatus ? $orderStatus : true, $this->__("Certegy Ezi-Pay authorisation success. Transaction #$transactionId"), $emailCustomer);
            $order->save();

            if ($emailCustomer) {
                $order->sendNewOrderEmail();
            }

            $invoiceAutomatically = Mage::getStoreConfig('payment/ezipayments/automatic_invoice');
            if ($invoiceAutomatically) {
                $this->invoiceOrder($order);
            }
        } else {
            $order->addStatusHistoryComment($this->__("Order #".($order->getId())." was declined by Certegy Ezi-Pay. Transaction #$transactionId."));
            $order
                ->cancel()
                ->setStatus(Ezipay_Ezipayments_Helper_OrderStatus::STATUS_DECLINED)
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was canceled by customer."));

            $order->save();
            // $this->restoreCart($order, true);
            $this->restoreCart($order);
        }
        Mage::getSingleton('checkout/session')->unsQuoteId(); 
        $this->sendResponse($isFromAsyncCallback, $result, $orderId); 
        return; 
    }

    private function statusExists($orderStatus) {
        try {
            $orderStatusModel = Mage::getModel('sales/order_status');
            if ($orderStatusModel) {
                $statusesResCol = $orderStatusModel->getResourceCollection();
                if ($statusesResCol) {
                    $statuses = $statusesResCol->getData();
                    foreach ($statuses as $status) {
                        if ($orderStatus === $status["status"]) return true;
                    }
                }
            }
        } catch(Exception $e) {
            Mage::log("Exception searching statuses: ".($e->getMessage()), Zend_Log::ERR, self::LOG_FILE);
        }
        return false;
    }

    private function sendResponse($isFromAsyncCallback, $result, $orderId){ 
        if($isFromAsyncCallback){ 
            // if from POST request (from asynccallback) 
            $jsonData = json_encode(["result"=>$result, "order_id"=> $orderId]); 
            $this->getResponse()->setHeader('Content-type', 'application/json'); 
            $this->getResponse()->setBody($jsonData); 
        } else { 
            // if from GET request (from browser redirect) 
            if($result=="completed"){ 
                $this->_redirect('checkout/onepage/success', array('_secure'=> false)); 
            }else{ 
                $this->_redirect('checkout/onepage/failure', array('_secure'=> false)); 
            } 
        } 
        return; 
    }

    private function invoiceOrder(Mage_Sales_Model_Order $order) {

        if(!$order->canInvoice()){
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        }

        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

        if (!$invoice->getTotalQty()) {
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
        }

        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
        $invoice->register();
        $transactionSave = Mage::getModel('core/resource_transaction')
        ->addObject($invoice)
        ->addObject($invoice->getOrder());

        $transactionSave->save();
    }

    /**
     * Constructs a request payload to send to ezipay
     * @return array
     */
    private function getPayload($order) {
        if($order == null)
        {
            Mage::log('Unable to get order from last lodged order id. Possibly related to a failed database call.', Zend_Log::ALERT, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
        }

        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();

        $billingAddressParts = explode(PHP_EOL, $billingAddress->getData('street'));
        $billingAddress0 = $billingAddressParts[0];
        $billingAddress1 = (count($billingAddressParts)>1)? $billingAddressParts[1]:'';

        if (!empty($shippingAddress)){
            $shippingAddressParts = explode(PHP_EOL, $shippingAddress->getData('street'));
            $shippingAddress0 = $shippingAddressParts[0];
            $shippingAddress1 = (count($shippingAddressParts)>1)? $shippingAddressParts[1]:'';
            $shippingAddress_city = $shippingAddress->getData('city');
            $shippingAddress_region = $shippingAddress->getData('region');
            $shippingAddress_postcode = $shippingAddress->getData('postcode');
        } else {
            $shippingAddress0 = "";
            $shippingAddress1 = "";
            $shippingAddress_city = "";
            $shippingAddress_region = "";
            $shippingAddress_postcode = "";
        }

        $orderId = (int)$order->getRealOrderId();
        $cancel_signature_query = ["orderId"=>$orderId, "amount"=>$order->getTotalDue(), "email"=>$order->getData('customer_email'), "firstname"=>$order->getCustomerFirstname(), "lastname"=>$order->getCustomerLastname()];
        $cancel_signature = Ezipay_Ezipayments_Helper_Crypto::generateSignature($cancel_signature_query, $this->getApiKey());
        $data = array(
            'x_currency'            => str_replace(PHP_EOL, ' ', $order->getOrderCurrencyCode()),
            'x_url_callback'        => str_replace(PHP_EOL, ' ', Ezipay_Ezipayments_Helper_Data::getCompleteUrl()),
            'x_url_complete'        => str_replace(PHP_EOL, ' ', Ezipay_Ezipayments_Helper_Data::getCompleteUrl()),
            'x_url_cancel'          => str_replace(PHP_EOL, ' ', Ezipay_Ezipayments_Helper_Data::getCancelledUrl($orderId) . "&signature=" . $cancel_signature),
            'x_shop_name'           => str_replace(PHP_EOL, ' ', Mage::app()->getStore()->getCode()),
            'x_account_id'          => str_replace(PHP_EOL, ' ', Mage::getStoreConfig('payment/ezipayments/merchant_number')),
            'x_reference'           => str_replace(PHP_EOL, ' ', $orderId),
            'x_invoice'             => str_replace(PHP_EOL, ' ', $orderId),
            'x_amount'              => str_replace(PHP_EOL, ' ', $order->getTotalDue()),
            'x_customer_first_name' => str_replace(PHP_EOL, ' ', $order->getCustomerFirstname()),
            'x_customer_last_name'  => str_replace(PHP_EOL, ' ', $order->getCustomerLastname()),
            'x_customer_email'      => str_replace(PHP_EOL, ' ', $order->getData('customer_email')),
            'x_customer_phone'      => str_replace(PHP_EOL, ' ', $billingAddress->getData('telephone')),
            'x_customer_billing_address1'  => $billingAddress0,
            'x_customer_billing_address2'  => $billingAddress1,
            'x_customer_billing_city'      => str_replace(PHP_EOL, ' ', $billingAddress->getData('city')),
            'x_customer_billing_state'     => str_replace(PHP_EOL, ' ', $billingAddress->getData('region')),
            'x_customer_billing_zip'       => str_replace(PHP_EOL, ' ', $billingAddress->getData('postcode')),
            'x_customer_shipping_address1' => $shippingAddress0,
            'x_customer_shipping_address2' => $shippingAddress1,
            'x_customer_shipping_city'     => str_replace(PHP_EOL, ' ', $shippingAddress_city),
            'x_customer_shipping_state'    => str_replace(PHP_EOL, ' ', $shippingAddress_region),
            'x_customer_shipping_zip'      => str_replace(PHP_EOL, ' ', $shippingAddress_postcode),
            'x_test'                       => 'false'
        );
        $apiKey    = $this->getApiKey();
        $signature = Ezipay_Ezipayments_Helper_Crypto::generateSignature($data, $apiKey);
        $data['x_signature'] = $signature;

        return $data;
    }

    /**
     * checks the quote for validity
     * @throws Mage_Api_Exception
     */
    private function validateQuote()
    {
        $specificCurrency = null;

        if ($this->getSpecificCountry() == self::EZIPAY_AU_COUNTRY_CODE) {
            $specificCurrency = self::EZIPAY_AU_CURRENCY_CODE;
        }

        $order = $this->getLastRealOrder();

        if($order->getTotalDue() < 100) {
            Mage::getSingleton('checkout/session')->addError("Certegy Ezi-Pay doesn't support purchases less than $100.");
            return false;
        }

        if($order->getBillingAddress()->getCountry() != $this->getSpecificCountry() || $order->getOrderCurrencyCode() != $specificCurrency ) {
            Mage::getSingleton('checkout/session')->addError("Orders from this country are not supported by Certegy Ezi-Pay. Please select a different payment option.");
            return false;
        }

        if( !$order->isVirtual && $order->getShippingAddress()->getCountry() != $this->getSpecificCountry()) {
            Mage::getSingleton('checkout/session')->addError("Orders shipped to this country are not supported by Certegy Ezi-Pay. Please select a different payment option.");
            return false;
        }

        return true;
    }

    /**
     * Get current checkout session
     * @return Mage_Core_Model_Abstract
     */
    private function getCheckoutSession() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Injects a self posting form to the page in order to kickoff ezipay checkout process
     * @param $checkoutUrl
     * @param $payload
     */
    private function postToCheckout($checkoutUrl, $payload)
    {
        echo
        "<html>
            <body>
            <form id='form' action='$checkoutUrl' method='post'>";
        foreach ($payload as $key => $value) {
                echo "<input type='hidden' id='$key' name='$key' value='".htmlspecialchars($value, ENT_QUOTES)."'/>";
            }
        echo
        '</form>
            </body>';
        echo
        '<script>
                var form = document.getElementById("form");
                form.submit();
            </script>
        </html>';
    }

    /**
     * returns an Order object based on magento's internal order id
     * @param $orderId
     * @return Mage_Sales_Model_Order
     */
    private function getOrderById($orderId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($orderId);
    }

    /**
     * retrieve the merchants ezipay api key
     * @return mixed
     */
    private function getApiKey()
    {
        return Mage::getStoreConfig('payment/ezipayments/api_key');
    }

    /**
    * Get specific country
    *
    * @return string
    */
    public function getSpecificCountry()
    {
      return Mage::getStoreConfig('payment/ezipayments/specificcountry');
    }

    /**
     * retrieve the last order created by this session
     * @return null
     */
    private function getLastRealOrder()
    {
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();

        $order =
            ($orderId)
                ? $this->getOrderById($orderId)
                : null;
        return $order;
    }

    /**
     * Method is called when an order is cancelled by a customer. As an Ezipay reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param Mage_Sales_Model_Order $order
     * @return $this
     * @throws Exception
     */
    private function cancelOrder(Mage_Sales_Model_Order $order)
    {
        if (!$order->isCanceled()) {
            $order
                ->cancel()
                ->setStatus(Ezipay_Ezipayments_Helper_OrderStatus::STATUS_CANCELED)
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was canceled by customer."));
        }
        return $this;
    }

    /**
     * Loads the cart with items from the order
     * @param Mage_Sales_Model_Order $order
     * @return $this
     */
    private function restoreCart(Mage_Sales_Model_Order $order, $refillStock = false)
    {
        // return all products to shopping cart
        $quoteId = $order->getQuoteId();
        $quote   = Mage::getModel('sales/quote')->load($quoteId);

        if ($quote->getId()) {
            $quote->setIsActive(1);
            if ($refillStock) {
                $items = $this->_getProductsQty($quote->getAllItems());
                if ($items != null ) {
                    Mage::getSingleton('cataloginventory/stock')->revertProductsSale($items);
                }
            }

            $quote->setReservedOrderId(null);
            $quote->save();
            $this->getCheckoutSession()->replaceQuote($quote);
        }
        return $this;
    }

    /**
     * Prepare array with information about used product qty and product stock item
     * result is:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     * @param array $relatedItems
     * @return array
     */
    protected function _getProductsQty($relatedItems)
    {
        $items = array();
        foreach ($relatedItems as $item) {
            $productId  = $item->getProductId();
            if (!$productId) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ($children) {
                foreach ($children as $childItem) {
                    $this->_addItemToQtyArray($childItem, $items);
                }
            } else {
                $this->_addItemToQtyArray($item, $items);
            }
        }
        return $items;
    }


    /**
     * Adds stock item qty to $items (creates new entry or increments existing one)
     * $items is array with following structure:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     *
     * @param Mage_Sales_Model_Quote_Item $quoteItem
     * @param array &$items
     */
    protected function _addItemToQtyArray($quoteItem, &$items)
    {
        $productId = $quoteItem->getProductId();
        if (!$productId)
            return;
        if (isset($items[$productId])) {
            $items[$productId]['qty'] += $quoteItem->getTotalQty();
        } else {
            $stockItem = null;
            if ($quoteItem->getProduct()) {
                $stockItem = $quoteItem->getProduct()->getStockItem();
            }
            $items[$productId] = array(
                'item' => $stockItem,
                'qty'  => $quoteItem->getTotalQty()
            );
        }
    }
}
