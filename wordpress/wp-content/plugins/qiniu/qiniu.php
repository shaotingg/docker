<?php
/*
Plugin Name: 七牛
Description: 七牛云是国内领先的以视觉智能和数据智能为核心的企业级云计算服务商，同时也是国内最有特色的智能视频云服务商，累计为 70 多万家企业提供服务，覆盖了国内80%网民。围绕富媒体场景推出了对象存储、融合 CDN 加速、容器云、大数据平台、深度学习平台等产品、并提供一站式智能视频云解决方案。为各行业及应用提供可持续发展的智能视频云生态，帮助企业快速上云，创造更广阔的商业价值。
Version:  1.0
Author: Lerte Smith
Author URI: https://lerte.com/
*/

if (!defined('ABSPATH')) exit;
require_once plugin_dir_path( __FILE__ ) . 'lib/qiniu/php-sdk-7.2.6/autoload.php';

use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use Qiniu\Cdn\CdnManager;

define('QINIU_SDK_VERSION', '7.2.6');
define('QINIU_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('QINIU_PLUGIN_URL', plugin_dir_url( __FILE__ ));

if(!class_exists('QiniuPlugin')):
class QiniuPlugin {
	public $auth;
	public $config;
	public $bucketMgr;
	public $uploadMgr;
	public $cdnManager;

	public function __construct(){
	    add_action('admin_init', array($this, 'qiniu_init'));
		add_action('admin_menu', array($this, 'qiniu_menu'));
		add_action('wp_ajax_get_options', array($this, 'get_options'));
		add_action('wp_ajax_set_options', array($this, 'set_options'));
		add_action('wp_ajax_get_bucket_files', array($this, 'get_bucket_files'));
		add_action('wp_ajax_del_bucket_file', array($this, 'del_bucket_file'));
		add_action('wp_ajax_upload_to_qiniu', array($this, 'upload_to_qiniu'));
		
	    add_filter('manage_media_columns', array($this, 'media_lib_add_upload_column') );
		add_action('manage_media_custom_column', array($this, 'media_lib_upload_column_value'), 0, 2);

		add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 1, 2);
		add_filter('wp_delete_file', array($this, 'delete_file'), 1, 1);
		add_filter('intermediate_image_sizes_advanced', array($this, 'add_image_insert_override'), 1, 2);
		add_filter('plugin_action_links', array($this, 'add_plugin_action_links'), 10, 2);
	}

	public function QiniuPlugin(){
		self::__construct();
	}
	
	function qiniu_include_assets() {
		wp_enqueue_style('mfp-style', QINIU_PLUGIN_URL . 'css/magnific-popup.min.css');
		wp_enqueue_style('qiniu-style', QINIU_PLUGIN_URL. 'css/qiniu.css?sdk='.QINIU_SDK_VERSION);
		wp_enqueue_script('jquery');
		wp_enqueue_script('magnific-popup', QINIU_PLUGIN_URL . 'js/jquery.magnific-popup.min.js');
		if(preg_match('/localhost|127.0.0.1/i', get_bloginfo('url'))){
			wp_enqueue_script('vue', QINIU_PLUGIN_URL . 'js/vue.js');
		}else{
			wp_enqueue_script('vue', QINIU_PLUGIN_URL . 'js/vue.min.js');
		}		
		wp_enqueue_script('qiniu', QINIU_PLUGIN_URL . 'js/main.js');
	}

	function qiniu_init(){
		$this->config = get_option('qiniu_options', array());
		$accessKey = isset($this->config['qiniu_ak']) ? $this->config['qiniu_ak'] : '';
		$secretKey = isset($this->config['qiniu_sk']) ? $this->config['qiniu_sk'] : '';
		if($accessKey && $secretKey){
			$this->auth = new Auth($accessKey, $secretKey);
			$this->uploadMgr = new UploadManager();
			$this->bucketMgr = new BucketManager($this->auth);
			$this->cdnManager = new CdnManager($this->auth);
		}else{
			return false;
		}
		list($buckets, $err) = $this->bucketMgr->buckets(true);
		if ($err) {
			return false;
		} else {
			return true;
		}
	}

    function set_options(){
        check_ajax_referer('qiniu', 'qiniu_ajax_nonce');
        $options = array();
        $options['qiniu_ak'] = sanitize_text_field($_POST['qiniu_ak']);
		$options['qiniu_sk'] = sanitize_text_field($_POST['qiniu_sk']);
		$options['bucket'] = sanitize_text_field($_POST['bucket']);
		$options['domain'] = sanitize_text_field($_POST['domain']);
		update_option('qiniu_options', $options);

		if($this->qiniu_init()){
			list($buckets, $err) = $this->bucketMgr->buckets(true);
			$options['buckets'] = $buckets;
			foreach($buckets as $bucket){
				list($domains, $err) = $this->bucketMgr->domains($bucket);
				$options["$bucket"] = $domains;
			}
			update_option('qiniu_options', $options);
		}
		
		wp_send_json($options);
        exit;
	}

    function get_options(){
        $options = get_option('qiniu_options');
        $options['qiniu_ajax_nonce'] = wp_create_nonce('qiniu');
		wp_send_json($options);
        exit;
	}

	function get_bucket_files(){
		$bucket = sanitize_text_field($_POST['bucket']);
		$prefix = sanitize_text_field($_POST['prefix']);
		$marker = sanitize_text_field($_POST['marker']);
		$limit = 100;
		$delimiter = '/';
		list($files, $err) = $this->bucketMgr->listFiles($bucket, $prefix, $marker, $limit, $delimiter);
		wp_send_json($files);
		exit;
	}

	function del_bucket_file(){
		$bucket = sanitize_text_field($_POST['bucket']);
		$key = sanitize_text_field($_POST['key']);
		$err = $this->bucketMgr->delete($bucket, $key);
		if($err){
			wp_send_json($err);
		}
		exit;
	}

	function HumanReadableFilesize($size) {
		$mod = 1024; 
		$units = explode(' ','B KB MB GB TB PB');
		for ($i = 0; $size > $mod; $i++) {
			$size /= $mod;
		}
		return round($size, 2) . ' ' . $units[$i];
	}

	function generate_qiniu_key($post_id){
		$attach_file = get_post_meta($post_id)['_wp_attached_file'][0];
		$file_dir = wp_upload_dir()['basedir'].DIRECTORY_SEPARATOR.$attach_file;
		$file_extension = pathinfo($attach_file)['extension'];
		if(!file_exists($file_dir)){
			return false;
		}
		$md5_hash = md5_file($file_dir).'.'.$file_extension;
		$qiniu_key = pathinfo($attach_file)['dirname'].DIRECTORY_SEPARATOR.$md5_hash;
		return $qiniu_key;
	}

	function get_file_path($post_id){
		$attach_file = get_post_meta($post_id)['_wp_attached_file'][0];
		$file_dir = wp_upload_dir()['basedir'].DIRECTORY_SEPARATOR.$attach_file;
		return $file_dir;
	}

	function media_lib_add_upload_column($cols){
		$cols['file_size'] = __('文件大小', 'qiniu');
		$cols['media_url'] = __('七牛', 'qiniu');
		return $cols;
	}

	function media_lib_upload_column_value($column_name, $post_id){
		wp_enqueue_script('upload', QINIU_PLUGIN_URL . 'js/upload.js');
		$file_dir = $this->get_file_path($post_id);
		if(!file_exists($file_dir)){
			echo '<span>'.__('文件不存在', 'qiniu').'</span>';
			return;
		}
		if($column_name == 'file_size'){
			echo $this->HumanReadableFilesize(filesize($file_dir));
		}
		if($column_name == 'media_url'){
			if(isset(get_post_meta($post_id)['qiniu-key'])){
				echo '<span style="line-height: 24px;"><img src='.QINIU_PLUGIN_URL . 'images/edit_icon.png style="vertical-align: middle;" width="24" height="24" />已上传</span>';
			}else{
				echo "<p data-post-id='$post_id' class='upload-to-qiniu button button-primary'>".__('上传至七牛', 'qiniu')."</p>";
			}
		}
	}

	function upload_to_qiniu(){
		$post_id = sanitize_text_field($_POST['post_id']);
		$expires = 3600;
		$filePath = $this->get_file_path($post_id);
		$key = $this->generate_qiniu_key($post_id);
		$upToken = $this->auth->uploadToken($this->config['bucket'], null, $expires, null, true);
		if(!$key){
			wp_send_json(array("error" => true, "message" => __('文件不存在', 'qiniu')));
		}
		list($ret, $err) = $this->uploadMgr->putFile($upToken, $key, $filePath);
		if ($err !== null) {
			wp_send_json(array("error" => true, "message" => $err));
		} else {
			update_post_meta($post_id, 'qiniu-key', $key);
			wp_send_json(array("success" => true, "message" => $ret));
		}
	}
	
	function get_attachment_url($url, $post_id){
		$this->config = get_option('qiniu_options', array());
		global $pagenow;
		if(!isset(get_post_meta($post_id)['qiniu-key'])){
			return $url;
		}else{
			$qiniu_key = get_post_meta($post_id)['qiniu-key'][0];
			if(strpos($url, $this->config['domain']) == false){
				if($pagenow == 'upload.php'){
					return 'http://'.$this->config['domain'].'/'.$qiniu_key.'?imageView2/1/w/140';
				}else{
					return 'http://'.$this->config['domain'].'/'.$qiniu_key;
				}
			}
		}
	}

	function delete_file($filepath){
		$basedir = wp_upload_dir()['basedir'];
		$attach_file = substr($filepath, strlen($basedir.'/'));
		$file_extension = pathinfo($attach_file)['extension'];
		$md5_hash = md5_file($filepath).'.'.$file_extension;
		$qiniu_key = pathinfo($attach_file)['dirname'].DIRECTORY_SEPARATOR.$md5_hash;

		$err = $this->bucketMgr->delete($this->config['bucket'], $qiniu_key);
		if($err){
			// ignore this
		}
		return $filepath;
	}

	function add_image_insert_override($sizes){
		$sizes = array();
		return $sizes;
	}

    function settings_page(){
        include_once(QINIU_PLUGIN_PATH.'views/header.php');
        include_once(QINIU_PLUGIN_PATH.'views/settings.php');
    }

    function library_page(){
        include_once(QINIU_PLUGIN_PATH.'views/header.php');
        include_once(QINIU_PLUGIN_PATH.'views/library.php');
	}

	function add_plugin_action_links($links, $file) {
		if ($file == plugin_basename(__FILE__)) {
			array_push($links, '<a href="admin.php?page=qiniu-settings">'.__('Settings').'</a>');
		}
		return $links;
	}
	
    function qiniu_menu(){
        add_menu_page(
            'qiniu',
            __('七牛'),
            'administrator',
            'qiniu-library',
            array($this, 'library_page'),
            QINIU_PLUGIN_URL . 'images/favicon.png'
        );
        
        add_submenu_page(
            'qiniu-library',
            __('七牛媒体库', 'qiniu'),
            __('Library'),
            'administrator',
            'qiniu-library',
            array($this, 'library_page')
        );
        
        add_submenu_page(
            'qiniu-library',
            __('七牛设置'),
            __('Settings'),
            'administrator',
            'qiniu-settings',
            array($this, 'settings_page')
        );
	}
}

$qiniu = new QiniuPlugin();

endif;