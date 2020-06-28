<?php
namespace acme;

use GuzzleHttp\Exception\ClientException;

/**
 * Github Api
 *
 * @author niqingyang
 *        
 */
class GithubApi
{
	
	/**
	 * 成功
	 */
	const API_SUCCESS = 0;
	
	/**
	 * 参数错误
	 */
	const API_PARAMS_ERROR = - 1;
	
	/**
	 * 网络错误
	 */
	const API_NETWORK_ERROR = - 2;
	
	/**
	 * 请求错误
	 */
	const API_REQUEST_ERROR = - 3;
	
	/**
	 * 数据完整性错误
	 */
	const API_INTEGRITY_ERROR = - 3;
	
	const BASE_URL = 'https://api.github.com/';
	
	const API_URL = '/repos/{owner}/{repo}/contents/{path}';
	
	// 协议有变动，需要将 access_token 放入 header 中发送
	// https://developer.github.com/changes/2020-02-10-deprecating-auth-through-query-param/
	const API_TOKEN_URL = '/repos/{owner}/{repo}/contents/{path}';
	
	// 暂时未用到
	const API_REF_URL = '/repos/{owner}/{repo}/git/refs/heads/master?access_token={token}';
	
	/**
	 * 访问令牌
	 *
	 * @var string
	 */
	private static $access_token;
	
	/**
	 *
	 * @var \GuzzleHttp\Client
	 */
	private static $client;
	
	/**
	 * 日志文件
	 *
	 * @var string|boolean false-禁止记录日志
	 */
	private static $log_file = false;
	
	/**
	 * 初始化
	 *
	 * @param array $config
	 */
	public static function init ($config = [])
	{
		static::$client = new \GuzzleHttp\Client([
			'verify' => false,
			'base_uri' => static::BASE_URL,
			'headers' => [
				'Content-Type' => 'application/json'
			]
		]);
		
		if(isset($config['access_token']))
		{
			static::setAccessToken($config['access_token']);
		}
		
		if(static::$log_file !== false)
		{
			static::$log_file = dirname(__DIR__) . '/info.log';
		}
	}
	
	/**
	 * 设置Token
	 *
	 * @param string $token
	 */
	public static function setAccessToken ($token)
	{
		static::$access_token = $token;
	}
	
	/**
	 * 获取文件的 sha 值
	 *
	 * @param string $owner
	 *        	用户名
	 * @param string $repo
	 *        	仓库名
	 * @param string $path
	 *        	文件路径
	 * @return string
	 */
	public static function getSha ($owner, $repo, $path)
	{
		if(empty($owner) || empty($repo) || empty($path))
		{
			return array(
				'code' => static::API_PARAMS_ERROR,
				'message' => 'path is empty'
			);
		}
		
		$url = strtr(static::API_TOKEN_URL, [
			'{owner}' => $owner,
			'{repo}' => $repo,
			'{path}' => $path
		]);
		
		try
		{
			// 通过 head 请求获取 sha 哈希值
			$response = static::$client->head($url, [
				'headers' => [
					'Authorization' => 'token ' . static::$access_token
				]
			]);
			
			$sha = trim(current($response->getHeader("etag")), "\"");
			
			return $sha;
		}
		catch(ClientException $e)
		{
			return false;
		}
	}
	
	/**
	 * 上传文件
	 *
	 * @param string $owner
	 *        	用户名
	 * @param string $repo
	 *        	仓库名
	 * @param string $srcPath
	 *        	本地文件路径
	 * @param string $dstPath
	 *        	上传的文件路径
	 * @param string $insertOnly
	 *        	同名文件是否覆盖
	 * @param integer $retry
	 *        	部分失败的原因会进行尝试重新上传，最多不超过重试次数
	 * @return array
	 */
	public static function upload ($owner, $repo, $srcPath, $dstPath, $message = '', $insertOnly = false, $sha = null, $retry = 3)
	{
		if(! file_exists($srcPath))
		{
			return array(
				'code' => static::API_PARAMS_ERROR,
				'message' => 'file ' . $srcPath . ' not exists',
				'data' => []
			);
		}
		
		$path = self::normalizerPath($dstPath, false);
		
		$url = strtr(static::API_TOKEN_URL, [
			'{owner}' => $owner,
			'{repo}' => $repo,
			'{path}' => ltrim($path, "/")
		]);
		
		try
		{
			
			$content = file_get_contents($srcPath);
			
			$body = [
				'message' => $message,
				'content' => base64_encode($content)
			];
			
			if($insertOnly == false && ! empty($sha))
			{
				$body['sha'] = $sha;
			}
			
			$response = static::$client->put($url, [
				'headers' => [
					'Authorization' => 'token ' . static::$access_token
				],
				'body' => json_encode($body)
			]);
			
			// 资源已被创建过，而且此次没有任何改动
			if($response->getStatusCode() == 201)
			{
			}
			
			$data = json_decode($response->getBody()->getContents(), JSON_OBJECT_AS_ARRAY);
			
			return array(
				'code' => 0,
				'data' => $data,
				'message' => 'ok'
			);
		}
		catch(ClientException $e)
		{
			if($e->getCode() == 409)
			{
				return array(
					'code' => $e->getCode(),
					'data' => [],
					'message' => '资源冲突'
				);
			}
			// 更新资源却没有提供 sha 签名值
			else if($e->getCode() == 422 && $insertOnly == false)
			{
				$sha = static::getSha($owner, $repo, $path);
				
				if(empty($sha))
				{
					return array(
						'code' => $e->getCode(),
						'data' => [],
						'message' => '更新时获取 sha 失败'
					);
				}
				
				return static::upload($owner, $repo, $srcPath, $dstPath, $message, $insertOnly, $sha);
			}
			else
			{
				return array(
					'code' => $e->getCode(),
					'data' => [],
					'message' => $e->getMessage()
				);
			}
		}
		catch(\Exception $e)
		{
			// 部分失败的原因会导致重试，不超过3次
			if($retry > 0 && strpos($e->getMessage(), 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL') !== false)
			{
				return static::upload($owner, $repo, $srcPath, $dstPath, $message, $insertOnly, $sha, $retry - 1);
			}
			
			return array(
				'code' => $e->getCode() == 0 ? 0 : static::API_REQUEST_ERROR,
				'data' => [],
				'message' => $e->getMessage()
			);
		}
	}
	
	/**
	 * 删除文件
	 *
	 * @param string $owner
	 * @param string $repo
	 * @param string $path
	 *        	文件路径
	 */
	public static function delFile ($owner, $repo, $path, $message = '')
	{
		if(empty($owner) || empty($repo) || empty($path))
		{
			return array(
				'code' => static::API_PARAMS_ERROR,
				'message' => 'path is empty'
			);
		}
		
		$path = self::normalizerPath($path);
		
		$url = strtr(static::API_TOKEN_URL, [
			'{owner}' => $owner,
			'{repo}' => $repo,
			'{path}' => ltrim($path, "/")
		]);
		
		try
		{
			$sha = static::getSha($owner, $repo, $path);
			
			if(empty($sha))
			{
				return false;
			}
			
			$response = static::$client->delete($url, [
				'headers' => [
					'Authorization' => 'token ' . static::$access_token
				],
				'body' => json_encode([
					'message' => $message,
					'sha' => $sha
				])
			]);
			
			if($response->getStatusCode() == 200)
			{
				return true;
			}
			
			return false;
		}
		catch(ClientException $e)
		{
			return false;
		}
	}
	
	/**
	 * 查询文件信息
	 *
	 * @param string $bucket
	 *        	bucket名称
	 * @param string $path
	 *        	文件路径
	 */
	public static function stat ($owner, $repo, $path)
	{
		$path = self::normalizerPath($path);
		
		$url = strtr(static::API_URL, [
			'{owner}' => $owner,
			'{repo}' => $repo,
			'{path}' => ltrim($path, "/")
		]);
		
		echo $url;
		
		try
		{
			$response = static::$client->get($url, [
				'headers' => [
					'Content-Type' => 'application/vnd.github.VERSION.html'
				]
			]);
			
			$data = json_decode($response->getBody()->getContents(), JSON_UNESCAPED_UNICODE);
			
			if($response->getStatusCode() == 200)
			{
				return array(
					'code' => static::API_SUCCESS,
					'data' => $data,
					'message' => 'ok'
				);
			}
			
			return array(
				'code' => $response->getStatusCode(),
				'data' => $data,
				'message' => 'fail'
			);
		}
		catch(ClientException $e)
		{
			return array(
				'code' => $e->getCode(),
				'data' => array(),
				'message' => $e->getMessage()
			);
		}
	}
	
	/**
	 * 内部方法, 规整文件路径
	 *
	 * @param string $path
	 *        	文件路径
	 * @param string $isfolder
	 *        	是否为文件夹
	 */
	private static function normalizerPath ($path, $isfolder = False)
	{
		if(preg_match('/^\//', $path) == 0)
		{
			$path = '/' . $path;
		}
		
		if($isfolder == True)
		{
			if(preg_match('/\/$/', $path) == 0)
			{
				$path = $path . '/';
			}
		}
		
		// Remove unnecessary slashes.
		$path = preg_replace('#/+#', '/', $path);
		
		return $path;
	}
	
	/**
	 * 记录错误信息
	 *
	 * @param string $message
	 * @return boolean
	 */
	public static function error ($message)
	{
		if(empty(static::$log_file))
		{
			return false;
		}
		
		$date = date('Y-m-d H:i:s');
		
		@file_put_contents(static::$log_file, "[ERROR][{$date}] " . $message . "\n", FILE_APPEND);
	}
	
	/**
	 * 记录日志信息
	 *
	 * @param string $message
	 * @return boolean
	 */
	public static function info ($message)
	{
		if(empty(static::$log_file))
		{
			return false;
		}
		
		$date = date('Y-m-d H:i:s');
		
		@file_put_contents(static::$log_file, "[INFO][{$date}] " . $message . "\n", FILE_APPEND);
	}
}