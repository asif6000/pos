<?php
$base = 'C:\xampp1\htdocs\pos\pos\pos';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

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
TestInputWrapper::$content = json_encode(['id' => 169]);

chdir($base . '\admin\api');
session_save_path($base . '\sessions');
session_id('testautoentry');
session_start();
$_SESSION['user_id'] = 29;
$_SESSION['user_name'] = 'yasin ali';
$_SESSION['user_email'] = 'yeasinali512629@gmail.com';
$_SESSION['user_role'] = 'admin';
$_SESSION['store_id'] = null;
$_SESSION['owner_id'] = 29;
session_write_close();

require $base . '\admin\api\delete-sale.php';
