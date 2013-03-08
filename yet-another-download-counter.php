<?php

	/*
	Plugin Name: Yet another download counter
	Plugin URI: http://azkotoki.org
	Description: Another download counter plugin. Simple, clean, no new tables.
	Author: Mikel Azkolain
	Version: 1.0
	Author URI: http://azkotoki.org
	*/
	
	class YADC_Model
	{
		const LockMetaName = '_yadc_update_lock';
		
		const CounterMetaName = '_yadc_counter';
		
		const LockTimeout = 10;
		
		protected function _getLock($attachment_id){
			do{
				
				//Try to save the lock meta field
				$res = add_post_meta(
					$attachment_id, self::LockMetaName, time(), true
				);
				
				//If obtaining the lock failed, check if it timed out
				if(!$res){
					
					//Get the current lock's date
					$lock_ts = get_post_meta(
						$attachment_id, self::LockMetaName, true
					);
					
					//If lock expired, regain it
					if($lock_ts + self::LockTimeout < time()){
						$res = update_post_meta(
							$attachment_id, self::LockMetaName, time()
						);
					}
					
					//If res is still false, let's sleep for a short period
					if(!$res){
						usleep(100000);
					}
				
				}
				
			}while(!$res);
		}
		
		protected function _releaseLock($attachment_id){
			delete_post_meta($attachment_id, self::LockMetaName);
		}
		
		public function getCount($attachment_id){
			return get_post_meta($attachment_id, self::CounterMetaName, true);
		}
		
		public function incrementCount($attachment_id){
			$this->_getLock($attachment_id);
			update_post_meta(
				$attachment_id,
				self::CounterMetaName,
				$this->getCount($attachment_id) + 1
			);
			$this->_releaseLock($attachment_id);
		}
	}
	
	function yadc_is_client_cache_valid($path){
		$client_date = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
		$file_date = filemtime($path);
	
		if(!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){
			return false;
	
		}else{
			$client_date = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
			$file_date = filemtime($path);
			return $client_date == $file_date;
		}
	}
	
	function yadc_handle_downlad(){
		$q = new WP_Query(array(
			'post_type' => 'attachment',
			'p' => $_GET['p'],
		));
	
		if($q->have_posts()){
			$post = $q->get_queried_object();
			$m = new YADC_Model();
			$m->incrementCount($post->ID);
			$file = get_attached_file($post->ID);
			session_cache_limiter(false);
				
			//Client cache is valid
			if(yadc_is_client_cache_valid($file)){
				header($_SERVER['SERVER_PROTOCOL'].' 304 Not Modified');
					
			//We have to send the file again
			}else{
				header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT', true);
				header('Content-Type: '.get_post_mime_type($post->ID));
				header('Content-Length: '.filesize($file));
				echo readfile($file);
			}
		}
	}
	
	function yadc_get_attachment_id($url){
		$query = array(
			'post_type' => 'attachment',
			'fields' => 'ids',
			'meta_query' => array(
				array(
					'value' => basename($url),
					'key' => '_wp_attached_file',
					'compare' => 'LIKE',
				)
			)
		);
		
		foreach(get_posts($query) as $id){
			$current_url = wp_get_attachment_url($id);
			if($url == $current_url){
				return $id;
			}
		}
	
		return false;
	}
	
	function yadc_attachment_url_filter($url){
		static $filter_running = false;
		
		if(!$filter_running){
			$filter_running = true;
			$att_id = yadc_get_attachment_id($url);
			$att_info = get_post($att_id);
			$url = site_url("/download-file/{$att_id}/{$att_info->post_name}");
			$filter_running = false;
		}
		
		return $url;
	}
	
	//Filter that rewrites the attachment urls
	add_filter('wp_get_attachment_url', 'yadc_attachment_url_filter');
	
	function yadc_add_media_details($form_fields, $post) {
		$m = new YADC_Model();
		$form_fields['yadc_download_count'] = array(
			'label' => __('Download count', 'yadc'),
			'input' => 'text',
			'value' => $m->getCount($post->ID),
			'helps' => __('Download count', 'yadc'),
		);
		return $form_fields;
	}
	
	//Add the edit field on the attachment details
	add_filter('attachment_fields_to_edit', 'yadc_add_media_details', null, 2);
	
	function yadc_save_media_details($post, $attachment){
		if(isset($attachment['yadc_download_count'])){
			update_post_meta(
				$post['ID'],
				YADC_Model::CounterMetaName,
				$attachment['yadc_download_count']
			);
		}
		
		return $post;
	}
	
	//Catch the custom field and save it
	add_filter('attachment_fields_to_save', 'yadc_save_media_details', null, 2);
	
	function yadc_column_header($cols) {
		$cols["yadc_downloads"] = "Downloads";
		return $cols;
	}
	
	function yadc_column_value($column_name, $att_id) {
		if($column_name == 'yadc_downloads'){
			$m = new YADC_Model();
			echo $m->getCount($att_id);
		}
	}
	
	//Add custom columns to media manager
	add_filter('manage_media_columns', 'yadc_column_header');
	add_action('manage_media_custom_column', 'yadc_column_value', 10, 2);
	
	function yadc_register_rewrite_rules(){
		$plugin_dir = basename(plugin_dir_path(__FILE__));
		add_rewrite_rule(
			'download-file/([0-9]+)/([^/]*)/?$',
			'wp-content/plugins/'.$plugin_dir.'/download.php?post_type=attachment&p=$1&name=$2',
			'top'
		);
	}
	
	//Register the url rewriter rules
	add_action('init', 'yadc_register_rewrite_rules');
	
	register_activation_hook(__FILE__, 'download_post_type_register_rewrite_flush');