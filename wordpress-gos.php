<?php
/*
 * Plugin Name: Github 附件存储
 * Plugin URI: https://github.com/niqingyang/wp-github-gos
 * Description: 使用 Github 作为附件存储空间
 * Version: 1.0
 * Author: niqingyang
 * Author URI: https://acme.top
 * License: MIT
 */
error_reporting(0);
require_once __DIR__ . '/vendor/autoload.php';

use acme\GithubApi;

if(! defined('WP_PLUGIN_URL'))
{
	define('WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins');
}
// plugin url
define('GITHUB_SYNC_BASENAME', plugin_basename(__FILE__));
define('GITHUB_SYNC_BASEFOLDER', plugin_basename(dirname(__FILE__)));

add_action('init', function ()
{
	load_plugin_textdomain('wordpress-gos', false, basename(dirname(__FILE__)) . '/lang');
});

// 初始化选项
register_activation_hook(__FILE__, 'github_set_options');

// 初始化选项
function github_set_options ()
{
	$options = array(
		'owner' => "",
		'repo' => "",
		'path' => "",
		'token' => "",
		'nothumb' => "false", // 是否上传所缩略图
		'nolocalsaving' => "false", // 是否保留本地备份
		'upload_url_path' => "" // URL前缀
	);
	$ret = add_option('github_sync_options', $options, '', 'yes');
	
	// 添加失败则获取已存在的数据，不为空则更新upload_url_path
	if($ret == false)
	{
		$options = get_option('github_sync_options', $options);
		
		if(! empty($options['upload_url_path']))
		{
			update_option('upload_url_path', $options['upload_url_path']);
		}
	}
}

// 停用插件
register_deactivation_hook(__FILE__, 'github_sync_stop');

function github_sync_stop ()
{
	$options = get_option('github_sync_options', TRUE);
	$upload_url_path = get_option('upload_url_path');
	$oss_upload_url_path = esc_attr($options['upload_url_path']);
	
	// 如果现在使用的是OSS的URL，则恢复原状
	if($upload_url_path == $oss_upload_url_path)
	{
		update_option('upload_url_path', "");
	}
}

// 设置COS所在的区域：
$github_sync_opt = get_option('github_sync_options', TRUE);
// 初始化
GithubApi::init([
	'access_token' => esc_attr($github_sync_opt['token'])
]);

/**
 * 上传函数
 *
 * @param
 *        	$object
 * @param
 *        	$file
 * @param
 *        	$opt
 * @return bool
 */
function github_file_upload ($object, $file, $opt = array())
{
	// 如果文件不存在，直接返回FALSE
	if(! @file_exists($file))
	{
		return FALSE;
	}
	
	// 获取WP配置信息
	$github_sync_options = get_option('github_sync_options', TRUE);
	$github_owner = esc_attr($github_sync_options['owner']);
	$github_repo = esc_attr($github_sync_options['repo']);
	
	if(@file_exists($file))
	{
		$ret = GithubApi::upload($github_owner, $github_repo, $file, $object);
		
		if($ret['code'] != 0)
		{
			GithubApi::error(json_encode($ret, JSON_UNESCAPED_UNICODE));
		}
		else
		{
			GithubApi::info(json_encode($ret, JSON_UNESCAPED_UNICODE));
		}
		
		return $ret;
	}
	else
	{
		return FALSE;
	}
}

/**
 * 是否需要删除本地文件
 *
 * @return bool
 */
function github_is_delete_local_file ()
{
	$github_sync_options = get_option('github_sync_options', TRUE);
	return (esc_attr($github_sync_options['nolocalsaving']) == 'true');
}

/**
 * 删除本地文件
 *
 * @param string $file
 *        	本地文件路径
 * @return bool
 */
function github_delete_local_file ($file)
{
	try
	{
		// 文件不存在
		if(! @file_exists($file))
		{
			return TRUE;
		}
		
		// 删除文件
		if(! @unlink($file))
		{
			return FALSE;
		}
		
		return TRUE;
	}
	catch(Exception $ex)
	{
		return FALSE;
	}
}

/**
 * 上传附件（包括图片的原图）
 *
 * @param
 *        	$metadata
 * @return array()
 */
function github_upload_attachments ($metadata)
{
	$wp_uploads = wp_upload_dir();
	// 生成object在OSS中的存储路径
	if(get_option('upload_path') == '.')
	{
		// 如果含有“./”则去除之
		$metadata['file'] = str_replace("./", '', $metadata['file']);
	}
	$object = str_replace("\\", '/', $metadata['file']);
	$object = str_replace(get_home_path(), '', $object);
	
	// 在本地的存储路径
	$file = get_home_path() . $object; // 向上兼容，较早的WordPress版本上$metadata['file']存放的是相对路径
	                                   
	// 设置可选参数
	$opt = array(
		'Content-Type' => $metadata['type']
	);
	
	// 执行上传操作
	$ret = github_file_upload('/' . $object, $file, $opt);
	
	if($ret !== false && is_array($ret) && $ret['code'] != 0)
	{
		return [
			'error' => $ret['message']
		];
	}
	
	// 获取URL参数
	if(@file_exists($file))
	{
		// 获取WP配置信息
		$github_sync_options = get_option('github_sync_options', TRUE);
		$upload_url_params = $github_sync_options['upload_url_params'];
		
		if(! empty($upload_url_params) && strpos($object, "?") === false)
		{
			$image_data = getimagesize($file);
			
			if($image_data != false)
			{
				$upload_url_params = strtr($upload_url_params, [
					'{width}' => $image_data[0],
					'{height}' => $image_data[1],
					'{size}' => $image_data['bits']
				]);
				$metadata['width'] = $image_data[0];
				$metadata['height'] = $image_data[1];
				$metadata['size'] = $image_data['bits'];
				
				$metadata['url'] .= '?' . trim($upload_url_params, '?');
			}
		}
	}
	
	// 如果不在本地保存，则删除本地文件
	if(github_is_delete_local_file())
	{
		github_delete_local_file($file);
	}
	return $metadata;
}

// 避免上传插件/主题时出现同步到COS的情况
if(substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0)
{
	add_filter('wp_handle_upload', 'github_upload_attachments', 50);
}

/**
 * 上传图片的缩略图
 */
function github_upload_thumbs ($metadata)
{
	// 上传所有缩略图
	if(isset($metadata['sizes']) && count($metadata['sizes']) > 0)
	{
		// 获取COS插件的配置信息
		$github_sync_options = get_option('github_sync_options', TRUE);
		// 是否需要上传缩略图
		$nothumb = (esc_attr($github_sync_options['nothumb']) == 'true');
		// 是否需要删除本地文件
		$is_delete_local_file = (esc_attr($github_sync_options['nolocalsaving']) == 'true');
		// 如果禁止上传缩略图，就不用继续执行了
		if($nothumb)
		{
			return $metadata;
		}
		// 获取上传路径
		$wp_uploads = wp_upload_dir();
		$basedir = $wp_uploads['basedir'];
		$file_dir = $metadata['file'];
		// 得到本地文件夹和远端文件夹
		$file_path = $basedir . '/' . dirname($file_dir) . '/';
		if(get_option('upload_path') == '.')
		{
			$file_path = str_replace("\\", '/', $file_path);
			$file_path = str_replace(get_home_path() . "./", '', $file_path);
		}
		else
		{
			$file_path = str_replace("\\", '/', $file_path);
		}
		
		$object_path = str_replace(get_home_path(), '', $file_path);
		
		// there may be duplicated filenames,so ....
		foreach($metadata['sizes'] as $val)
		{
			// 生成object在COS中的存储路径
			$object = '/' . $object_path . $val['file'];
			// 生成本地存储路径
			$file = $file_path . $val['file'];
			// 设置可选参数
			$opt = array(
				'Content-Type' => $val['mime-type']
			);
			
			// 执行上传操作
			github_file_upload($object, $file, $opt);
			
			// 如果不在本地保存，则删除
			if($is_delete_local_file)
			{
				github_delete_local_file($file);
			}
		}
	}
	return $metadata;
}

// 避免上传插件/主题时出现同步到COS的情况
if(substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0)
{
	add_filter('wp_generate_attachment_metadata', 'github_upload_thumbs', 100);
}

/**
 * 删除远程服务器上的单个文件
 */
function github_delete_remote_file ($file)
{
	// 获取WP配置信息
	$github_sync_options = get_option('github_sync_options', TRUE);
	$github_owner = esc_attr($github_sync_options['owner']);
	$github_repo = esc_attr($github_sync_options['repo']);
	
	// 得到远程路径
	$file = str_replace("\\", '/', $file);
	$del_file_path = str_replace(get_home_path(), '/', $file);
	try
	{
		// 删除文件
		GithubApi::delFile($github_bucket, $github_repo, $del_file_path);
	}
	catch(Exception $ex)
	{
	}
	return $file;
}
add_action('wp_delete_file', 'github_delete_remote_file', 100);

// 当upload_path为根目录时，需要移除URL中出现的“绝对路径”
function modefiy_img_url ($url, $post_id)
{
	$home_path = str_replace(array(
		'/',
		'\\'
	), array(
		'',
		''
	), get_home_path());
	$url = str_replace($home_path, '', $url);
	return $url;
}

if(get_option('upload_path') == '.')
{
	add_filter('wp_get_attachment_url', 'modefiy_img_url', 30, 2);
}

function github_read_dir_queue ($dir)
{
	if(isset($dir))
	{
		$files = array();
		$queue = array(
			$dir
		);
		while($data = each($queue))
		{
			$path = $data['value'];
			if(is_dir($path) && $handle = opendir($path))
			{
				while($file = readdir($handle))
				{
					if($file == '.' || $file == '..')
					{
						continue;
					}
					
					$files[] = $real_path = $path . '/' . $file;
					if(is_dir($real_path))
					{
						$queue[] = $real_path;
					}
					
					// echo explode(get_option('upload_path'),$path)[1];
				}
			}
			closedir($handle);
		}
		$i = '';
		foreach($files as $v)
		{
			$i ++;
			if(! is_dir($v))
			{
				$dd[$i]['j'] = $v;
				$dd[$i]['x'] = '/' . get_option('upload_path') . explode(get_option('upload_path'), $v)[1];
			}
		}
	}
	else
	{
		$dd = '';
	}
	return $dd;
}

// 在插件列表页添加设置按钮
function github_plugin_action_links ($links, $file)
{
	if($file == plugin_basename(dirname(__FILE__) . '/wordpress-gos.php'))
	{
		$links[] = '<a href="options-general.php?page=' . GITHUB_SYNC_BASEFOLDER . '/wordpress-gos.php">' . 设置 . '</a>';
	}
	return $links;
}
add_filter('plugin_action_links', 'github_plugin_action_links', 10, 2);

// 在导航栏“设置”中添加条目
function github_add_setting_page ()
{
	add_options_page('Github 存储', 'Github 存储', 'manage_options', __FILE__, 'github_setting_page');
}

add_action('admin_menu', 'github_add_setting_page');

// 插件设置页面
function github_setting_page ()
{
	if(! current_user_can('manage_options'))
	{
		wp_die('Insufficient privileges!');
	}
	$options = array();
	if(! empty($_POST) and $_POST['type'] == 'github_set')
	{
		$options['owner'] = (isset($_POST['owner'])) ? trim(stripslashes($_POST['owner'])) : '';
		$options['repo'] = (isset($_POST['repo'])) ? trim(stripslashes($_POST['repo'])) : '';
		$options['token'] = (isset($_POST['token'])) ? trim(stripslashes($_POST['token'])) : '';
		$options['nothumb'] = (isset($_POST['nothumb'])) ? 'true' : 'false';
		$options['nolocalsaving'] = (isset($_POST['nolocalsaving'])) ? 'true' : 'false';
		// 仅用于插件卸载时比较使用
		$options['upload_url_path'] = (isset($_POST['upload_url_path'])) ? trim(stripslashes($_POST['upload_url_path'])) : '';
		$options['upload_url_params'] = (isset($_POST['upload_url_params'])) ? trim(stripslashes($_POST['upload_url_params'])) : '';
	}
	
	if(! empty($_POST) and $_POST['type'] == 'github_sync_all')
	{
		// 设置不超时
		set_time_limit(0);
		
		$github_sync_options = get_option('github_sync_options', TRUE);
		$github_owner = esc_attr($github_sync_options['owner']);
		$github_repo = esc_attr($github_sync_options['repo']);
		
		$synv = github_read_dir_queue(get_home_path() . get_option('upload_path'));
		$i = 0;
		foreach($synv as $k)
		{
			// 判断文件是否存在
			$sha = GithubApi::getSha($github_owner, $github_repo, $k['x']);
			
			if(empty($sha))
			{
				$i ++;
				github_file_upload($k['x'], $k['j']);
			}
		}
		echo '<div class="updated"><p><strong>本次操作成功同步' . $i . '个文件</strong></p></div>';
	}
	
	// 替换数据库链接
	if(! empty($_POST) and $_POST['type'] == 'qcloud_cos_replace')
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'posts';
		$oldurl = trim($_POST['old_url']);
		$newurl = trim($_POST['new_url']);
		
		if(empty($oldurl))
		{
			echo '<div class="error"><p><strong>要替换的旧域名不能为空！</strong></p></div>';
		}
		
		if(empty($newurl))
		{
			echo '<div class="error"><p><strong>要替换的新域名不能为空！</strong></p></div>';
		}
		
		if(! empty($oldurl) && ! empty($newurl))
		{
			$result = $wpdb->query("UPDATE $table_name SET post_content = REPLACE( post_content, '$oldurl', '$newurl') ");
			
			echo '<div class="updated"><p><strong>替换成功！共批量执行' . $result . '条！</strong></p></div>';
		}
	}
	// 若$options不为空数组，则更新数据
	if($options !== array())
	{
		// 更新数据库
		update_option('github_sync_options', $options);
		
		$upload_path = trim(trim(stripslashes($_POST['upload_path'])), '/');
		$upload_path = ($upload_path == '') ? ('wp-content/uploads') : ($upload_path);
		update_option('upload_path', $upload_path);
		
		$upload_url_path = trim(trim(stripslashes($_POST['upload_url_path'])), '/');
		update_option('upload_url_path', $upload_url_path);
		
		$upload_url_params = trim(trim(stripslashes($_POST['upload_url_params'])), '?');
		update_option('upload_url_params', $upload_url_params);
		
		?>
<div class="updated">
	<p>
		<strong>设置已保存！</strong>
	</p>
</div>
<?php
	}
	
	$github_sync_options = get_option('github_sync_options', TRUE);
	$upload_path = get_option('upload_path');
	$upload_url_path = get_option('upload_url_path');
	$upload_url_params = get_option('upload_url_params');
	
	$github_owner = esc_attr($github_sync_options['owner']);
	$github_repo = esc_attr($github_sync_options['repo']);
	$github_token = esc_attr($github_sync_options['token']);
	
	$github_nothumb = esc_attr($github_sync_options['nothumb']);
	$github_nothumb = ($github_nothumb == 'true');
	
	$github_nolocalsaving = esc_attr($github_sync_options['nolocalsaving']);
	$github_nolocalsaving = ($github_nolocalsaving == 'true');
	?>
<div class="wrap" style="margin: 10px;">
	<h2><?php _e('Github 附件存储设置', 'wordpress-gos')?></h2>

	<form name="form1" method="post" action="<?php echo wp_nonce_url('./options-general.php?page=' . GITHUB_SYNC_BASEFOLDER . '/wordpress-gos.php'); ?>">
		<table class="form-table">
			<tr>
				<th><legend><?php _e('用户名', 'wordpress-gos')?></legend></th>
				<td><input type="text" name="owner" value="<?php echo $github_owner; ?>" size="50" placeholder="<?php _e('用户名', 'wordpress-gos')?>" />
					<p>
						<?php _e('请先访问 <a href="https://github.com/" target="_blank">Github</a> 创建，再填写以上内容。', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend><?php _e('仓库名', 'wordpress-gos')?></legend></th>
				<td><input type="text" name="repo" value="<?php echo $github_repo; ?>" size="50" placeholder="<?php _e('仓库名', 'wordpress-gos')?>" />
					<p>
						<?php _e('请先访问 <a href="https://github.com/" target="_blank">Github</a> 创建 <code>仓库</code>，再填写以上内容。', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend>Access Token</legend></th>
				<td><input type="text" name="token" value="<?php echo $github_token; ?>" size="50" placeholder="<?php _e('access token', 'wordpress-gos')?>" />
					<p>
						<?php _e('请先访问 <a href="https://github.com/" target="_blank">Github</a> 创建 <code>access token</code>，再填写以上内容。', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend><?php _e('不上传缩略图', 'wordpress-gos')?></legend></th>
				<td><input type="checkbox" name="nothumb" <?php
	
	if($github_nothumb)
	{
		echo 'checked="TRUE"';
	}
	?> />

					<p><?php _e('建议不勾选', 'wordpress-gos')?></p></td>
			</tr>
			<tr>
				<th><legend><?php _e('不在本地保留备份', 'wordpress-gos')?></legend></th>
				<td><input type="checkbox" name="nolocalsaving" <?php
	
	if($github_nolocalsaving)
	{
		echo 'checked="TRUE"';
	}
	?> />

					<p><?php _e('建议不勾选', 'wordpress-gos')?></p></td>
			</tr>
			<tr>
				<th><legend><?php _e('本地文件夹', 'wordpress-gos')?></legend></th>
				<td><input type="text" name="upload_path" value="<?php echo $upload_path; ?>" size="50" placeholder="<?php _e('请输入上传文件夹', 'wordpress-gos')?>" />

					<p>
						<?php _e('附件在服务器上的存储位置，例如： <code>wp-content/uploads</code> （注意不要以“/”开头和结尾），根目录请输入 <code>.</code>。', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend><?php _e('URL前缀', 'wordpress-gos')?></legend></th>
				<td><input type="text" name="upload_url_path" value="<?php echo $upload_url_path; ?>" size="50" placeholder="<?php _e('请输入URL前缀', 'wordpress-gos')?>" />
					<p>
						<b><?php _e('注意：', 'wordpress-gos')?></b>
					</p>
					<p>
						<?php _e('1）URL前缀的格式为 <code>https://raw.githubusercontent.com/{用户名}/{仓库名}/master/</code> <code>.</code> 时），或者 <code>https://raw.githubusercontent.com/{用户名}/{仓库名}/master/{本地文件夹}</code>，“本地文件夹”务必与上面保持一致（结尾无<code>/</code>）。', 'wordpress-gos')?>
					</p>
					<p>
						<?php _e('2）github中的存放路径（即“文件夹”）与上述 <code>本地文件夹</code> 中定义的路径是相同的（出于方便切换考虑）。', 'wordpress-gos')?>
					</p>
					<p>
						<?php _e('3）如果需要使用 <code>独立域名</code> ，直接将 <code>{域名}</code> 替换为 <code>独立域名</code> 即可。', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend><?php _e('URL参数', 'wordpress-gos')?></legend></th>
				<td><input type="text" name="upload_url_params" value="<?php echo $upload_url_params; ?>" size="50" placeholder="<?php _e('请输入URL参数', 'wordpress-gos')?>" />
					<p>
						<b><?php _e('注意：', 'wordpress-gos')?></b>
					</p>
					<p>
						<?php _e('1）URL参数仅对图片起作用，支持向URL后面添加width（宽度）、height（高度）、size（大小）三个参数，例如：输入w={width}&h={height}&s={size} 会生成图片链接 http://xxx.xxx/xxx/xxx/demo.png?w=200&h=300&size=12345', 'wordpress-gos')?>
					</p></td>
			</tr>
			<tr>
				<th><legend><?php _e('更新选项', 'wordpress-gos')?></legend></th>
				<td><input type="submit" name="submit" value="<?php _e('更新', 'wordpress-gos')?>" /></td>
			</tr>
		</table>
		<input type="hidden" name="type" value="github_set">
	</form>
	<form name="form1" method="post" action="<?php echo wp_nonce_url('./options-general.php?page=' . GITHUB_SYNC_BASEFOLDER . '/wordpress-gos.php'); ?>">
		<table class="form-table">
			<tr>
				<th><legend><?php _e('同步历史附件', 'wordpress-gos')?></legend></th>
				<input type="hidden" name="type" value="github_sync_all">
				<td><input type="submit" name="submit" value="<?php _e('开始同步', 'wordpress-gos')?>" />
					<p>
						<b>
							<?php _e('注意：如果是首次同步，执行时间将会十分十分长（根据你的历史附件数量），有可能会因执行时间过长，页面显示超时或者报错。<br> 所以，建议那些几千上万附件的大神们，直接使用 Git 命令自主同步', 'wordpress-gos')?>
						</b>
					</p></td>
			</tr>
		</table>
	</form>
	<hr>
	<form name="form1" method="post" action="<?php echo wp_nonce_url('./options-general.php?page=' . GITHUB_SYNC_BASEFOLDER . '/wordpress-gos.php'); ?>">
		<table class="form-table">
			<tr>
				<th>                       <legend><?php _e('数据库原链接替换', 'wordpress-gos')?></legend>
				</th>
				<td><input type="text" name="old_url" size="50" required="required" style="width: 450px" placeholder="<?php _e('请输入要替换的旧域名', 'wordpress-gos')?>" /></td>
			</tr>
			<tr>
				<th><legend></legend></th>
				<td><input type="text" name="new_url" size="50" required="required" style="width: 450px" placeholder="<?php _e('请输入要替换的新域名', 'wordpress-gos')?>" /></td>
			</tr>
			<tr>
				<th>                        <legend></legend>
				</th>
				<input type="hidden" name="type" value="qcloud_cos_replace">
				<td><input type="submit" name="submit" value="<?php _e('开始替换', 'wordpress-gos')?>" />                    
					<p>
						<b><?php _e('注意：如果是首次替换，请注意备份！此功能只限于替换文章中使用的资源链接', 'wordpress-gos')?></b>
					</p></td>
			</tr>
		</table>
	</form>
</div>
<?php
}
?>
