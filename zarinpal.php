<?php
/**
 * CodeIgniter ZarinPal library
 * For Version 2.0 or later.
 *
 * @author              Ehsan (http://iehsan.ir)
 * @license             GNU Public License 2.0
 * @package             ZarinPal
 */
if ( ! defined( 'BASEPATH' ) ) exit( 'Sry.' );
$GLOBALS['CI'] =& get_instance();
if ( ! class_exists( 'nusoap_client' ) ) require_once( 'nusoap.php' );

class zarinpal {
    private $payment_meta;
    private $payment_gate;
    
    public function create_payment( $merchant_id = '', $amount = 0, $gateway = 'ZarinGate' ) {
        if ( $this->payment_meta || ! is_null( $this->payment_meta ) ) {
            throw new Exception( 'There is an abandoned request. Use Zarinpal::remove_payment() to continue' );
            return false;
        }
        if ( $merchant_id == '' || strlen( $merchant_id ) != 16 ) {
            throw new Exception( 'Invalid $merchant_id data. Use a true one.' );
            return false;
        }
        if ( $amount <= 0 ) {
            throw new Exception( 'Invalid $amount data. Use a true one.' );
            return false;
        }
        if ( ! $gateway == 'ZarinGate' || ! $gateway == 'WebGate' ) {
            throw new Exception( 'Invalid $gateway data. Use "ZarinGate" or "WebGate"' );
            return false;
        }
        
        $this->payment_meta = array(
            'MerchantID'       =>  $merchant_id,
            'Amount'            =>  $amount
        );
        $this->payment_gate = $gateway;
        return true;
    }
    
    public function start_payment( $callback_url = '', $desc = '', $mobile = '', $email = '' ) {
       if ( ! $this->payment_meta || is_null( $this->payment_meta ) ) {
           throw new Exception( 'There is not any payment meta. Use Zarinpal::create_payment()' );
           return false;
       }
        $this->payment_meta['CallbackURL'] = $callback_url;
        if ( isset( $mobile ) ) $this->payment_meta['Mobile'] = $mobile;
        if ( isset( $email ) ) $this->payment_meta['Email'] = $email;
        $this->payment_meta['Description'] = $desc;
        
        $client = new nusoap_client( 'https://de.zarinpal.com/pg/services/WebGate/wsdl', 'wsdl' );
        $client->soap_defencoding = 'UTF-8';
        $result = $client->call( 'PaymentRequest', array( array( $this->payment_meta ) ) );
        
        $row = array(
            'merchant'  =>  $this->payment_meta['MerchantID'],
            'authority' =>  $result['Authority'] or 'error',
            'date'      =>  time(),
            'amount'    =>  $this->payment_meta['Amount'],
            'status'    =>  $result['Status'] or 'error'
        );
        global $CI;
        $CI->load->database();
        $this->create_payments_table();
        $CI->db->insert( '_zp_payments', $row );
        
        if ( $result['Status'] == 100 ) {
            if ( $this->payment_gate == 'ZarinGate' ) {
                $uri = 'https://www.zarinpal.com/pg/StartPay/%s/ZarinGate';
            } else {
                $uri = 'https://www.zarinpal.com/pg/StartPay/%s';
            }
            return sprintf( $uri, $result['Authority'] );
        } else {
            return intval( $result['Status'] ) * -1;
        }
    }
    
    public function verify_payment( $authority ) {
        global $CI;
        $ci->load->database();
        $query = $CI->db->query( "SELECT * FROM _zp_payments WHERE authority = '$authority' LIMIT 1" );
        if ( ! $query->num_rows() ) {
            throw new Exception( 'The record does not exists on database' );
            return false;
        }
        $row = $query->row();
        $amount = intval( $row->amount );
        $merchant = $row->merchant;
        
        $client = new nusoap_client( 'https://de.zarinpal.com/pg/services/WebGate/wsdl', 'wsdl' );
        $client->soap_defencoding =  'UTF-8';
        
        $result = $client->call( 'PaymentVerification', array( array ( 
            'MerchantID'    =>  $merchant,
            'Authority'     =>  $authority,
            'Amount'        =>  $amount
        ) ) );
        
        $CI->db->update( '_zp_payments', array( 
            'status'    =>  $result['Status']
        ));
        
        return $result;
    }
    
    public function remove_payment() {
        $this->payment_meta = null;
    }
                                
    private function create_payments_table() {
        global $CI;
        $CI->load->database();
        return $CI->db->query(
            'CREATE TABLE IF NOT EXISTS `_zp_payments` (
                `ID` int(11) NOT NULL AUTO_INCREMENT,
                `authority` varchar(36) NOT NULL,
                `merchant` varchar(36) NOT NULL,
                `date` varchar(16) NOT NULL,
                `amount` int(11) NOT NULL,
                PRIMARY KEY (`ID`)
            ) DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;'
        );
    }
}