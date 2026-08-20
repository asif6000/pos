<?php
class TestInputWrapper {
    public $context;
    public static $content = '';
    private $position = 0;
    public function stream_open($path, $mode, $options, &$opened_path) { return true; }
    public function stream_read($count) { $data = substr(self::$content, $this->position, $count); $this->position += strlen($data); return $data; }
    public function stream_eof() { return $this->position >= strlen(self::$content); }
    public function stream_stat() { return []; }
    public function url_stat($path, $flags) { return []; }
    public function stream_set_option($option, $arg1, $arg2) { return true; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'TestInputWrapper');
TestInputWrapper::$content = json_encode([
    'customer_id' => 0,
    'subtotal' => 900,
    'discount_percent' => 0,
    'discount_amount' => 0,
    'vat_percent' => 0,
    'vat_amount' => 0,
    'total' => 900,
    'paid_amount' => 900,
    'change_amount' => 0,
    'payment_method' => 'cash',
    'items' => [
        ['product_id' => 42, 'quantity' => 10, 'product_name' => 'PANT', 'unit_price' => 90, 'total_price' => 900]
    ]
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
session_save_path(__DIR__ . '/sessions');
session_id('testautoentry');
session_start();
$_SESSION['user_id'] = 29;
$_SESSION['user_name'] = 'yasin ali';
$_SESSION['user_email'] = 'yeasinali512629@gmail.com';
$_SESSION['user_role'] = 'admin';
$_SESSION['store_id'] = null;
$_SESSION['owner_id'] = 29;
session_write_close();

require __DIR__ . '/admin/api/process-sale.php';
