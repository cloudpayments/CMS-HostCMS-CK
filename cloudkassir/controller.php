<?php

defined('HOSTCMS') || exit('HostCMS: access denied.');

class Cashregister_Cloudkassir_Controller extends Cashregister_Controller
{
    const RECEIPT_TYPE_INCOME = 'Income';
    const RECEIPT_TYPE_INCOME_RETURN = 'IncomeReturn';

    const API_URL = 'https://api.cloudpayments.ru/';

    /**
     * Get driver settings list
     * @return array
     */
    public function getSettingsList()
    {
        return array(
            'public_id',
            'secret_key',
            'taxation_system',
            'vat_delivery',
        );
    }

    private function _createReceipt($receiptType)
    {
        $aConfig = Core_Config::instance()->get('cashregister_config');

        $oShop_Order = $this->_oShop_Order;

        $aReceiptData = array(
            'Items' => array(),
            'taxationSystem' => Core_Array::get($aConfig[$this->_oCashregister->id], 'taxation_system', '0'),
            'email' => $oShop_Order->email,
            'phone' => $this->sanitizePhoneNumber($oShop_Order->phone)
        );

        $aVat = array(
            0 => '',
            10 => '10',
            18 => '18'
        );

        $aItems = $this->getOrderItems();
        foreach ($aItems as $aItem) {
            $aItemVat = Core_Array::get($aVat, $aItem['tax'], '');
            if ($aItem['shop_item_id'] == 0) {
                $aItemVat = Core_Array::get($aConfig[$this->_oCashregister->id], 'vat_delivery', '');
            }
            $aReceiptData['Items'][] = [
                'label' => $aItem['name'],
                'price' => floatval($aItem['price']),
                'quantity' => floatval($aItem['quantity']),
                'amount' => floatval($aItem['quantity']) * floatval($aItem['price']),
                'vat' => $aItemVat
            ];
        }

        $aResponse = $this->_makeRequest('kkt/receipt', [
            'Inn' => $this->_oCashregister->tin,
            'Type' => $receiptType,
            'CustomerReceipt' => $aReceiptData,
            'InvoiceId' => $oShop_Order->id,
            'AccountId' => strlen($oShop_Order->email) ? strval($oShop_Order->email) : '',
        ]);

        $oCashregister_Receipt = Core_Entity::factory('Cashregister_Receipt');
        $oCashregister_Receipt
            ->cashregister_id($this->_oCashregister->id)
            ->shop_order_id($oShop_Order->id)
            ->datetime(Core_Date::timestamp2sql(time()))
            ->phone($this->sanitizePhoneNumber($oShop_Order->phone))
            ->email($oShop_Order->email)
            ->amount($oShop_Order->getAmount())
            ->mode($this->_oCashregister->mode)
            ->type($receiptType == self::RECEIPT_TYPE_INCOME_RETURN ? 1 : 0);

        if (isset($aResponse['Model']['Id'])) {
            $oCashregister_Receipt
                ->guid($aResponse['Model']['Id'])
                ->next_update(Core_Date::timestamp2sql(time() + 60)); // +1 minute
        } else {
            $oCashregister_Receipt->next_update = null;
            isset($aAnswer['error']['text'])
            && $oCashregister_Receipt->error($aResponse['Message']);
        }

        $oCashregister_Receipt->save();

        foreach ($aItems as $aItem) {
            $oCashregister_Receipt_Item = Core_Entity::factory('Cashregister_Receipt_Item');
            $oCashregister_Receipt_Item
                ->cashregister_receipt_id($oCashregister_Receipt->id)
                ->shop_item_id($aItem['shop_item_id'])
                ->name($aItem['name'])
                ->price($aItem['price'])
                ->tax($aItem['tax'])
                ->quantity($aItem['quantity'])
                ->save();
        }

        return TRUE;
    }

    public function execute()
    {
        return $this->_createReceipt(self::RECEIPT_TYPE_INCOME);
    }

    public function refund()
    {
        return $this->_createReceipt(self::RECEIPT_TYPE_INCOME_RETURN);
    }


    /**
     * @param string $location
     * @param array $request
     * @return array
     */
    private function _makeRequest($location, $request = array())
    {
        $aConfig = Core_Config::instance()->get('cashregister_config');

        try {
            $auth = implode(':', [
                Core_Array::get($aConfig[$this->_oCashregister->id], 'public_id'),
                Core_Array::get($aConfig[$this->_oCashregister->id], 'secret_key')
            ]);
            $Core_Http = Core_Http::instance('curl')
                ->clear()
                ->method('POST')
                ->url(self::API_URL . $location)
                ->additionalHeader('Content-Type', 'application/json; charset=utf-8')
                ->config(
                    array(
                        'options' => array(
                            CURLOPT_USERPWD => $auth,
                        )
                    )
                )
                ->rawData(json_encode($request))
                ->execute();

            $aResponse = json_decode($Core_Http->getBody(), TRUE);
        } catch (Exception $e) {
            $aResponse = [
                'Success' => FALSE,
                'Message' => $e->getMessage()
            ];
        }

        return $aResponse;
    }

    /**
     * Sanitize phone number
     * @param $phone
     * @return string
     */
    public function sanitizePhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // 8928 => 7928
        substr($phone, 0, 2) == '89' && $phone{0} = ' ';

        return trim($phone);
    }

    /**
     * Update receipt status
     * @return object
     */
    public function updateReceiptStatus(Cashregister_Receipt_Model $oCashregister_Receipt)
    {
        return $this;
    }

    /**
     * callback function
     * @return boolean
     */
    public function callback($input)
    {
        $aData = $_POST;
        if (isset($aData['DocumentNumber'], $aData['FiscalSign'])) {
            header('Content-Type: application/json');

            if (isset($aData['Id'])) {
                $oCashregister_Receipt = Core_Entity::factory('Cashregister_Receipt')->getByGuid($aData['Id']);

                if (!is_null($oCashregister_Receipt)) {
                    $aConfig = Core_Config::instance()->get('cashregister_config');
                    $secretKey = Core_Array::get($aConfig[$oCashregister_Receipt->cashregister_id], 'secret_key');
                    $checkSign = base64_encode(hash_hmac('SHA256', $input, $secretKey, TRUE));
                    $requestSign = isset($_SERVER['HTTP_CONTENT_HMAC']) ? $_SERVER['HTTP_CONTENT_HMAC'] : '';

                    if ($checkSign !== $requestSign) {
                        echo json_encode(['code' => 13, 'msg' => 'Failed signature']);

                        return FALSE;
                    }

                    $oCashregister_Receipt->next_update = null;

                    $oCashregister_Receipt
                        ->fd($aData['DocumentNumber'])
                        ->fpd($aData['FiscalSign'])
                        ->save();
                    echo json_encode(['code' => 0]);

                    return TRUE;
                } else {
                    echo json_encode(['code' => 13, 'msg' => 'Receipt not found']);

                    return FALSE;
                }
            }
        }

        return null;
    }
}