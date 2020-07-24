
<p align="center">
    <img src="/wp_github_gos.png" alt="wordpress-qcloud-cos" align="center" />
</p>
<p align="center">利用 github api 实现的一个存储附件（图床）的 wordpress 插件</p>


## 前言

本插件核心功能使用了 GitHub API

设置页面和核心业务逻辑主要参考插件 [wordpress-qcloud-cos](https://github.com/sy-records/wordpress-qcloud-cos) 实现，替换了其中 腾讯云 COS 官方 SDK 为 GitHub API

## 插件特色

使用 GitHub 存储服务存储 WordPress 站点图片等多媒体文件

可配置是否上传缩略图和是否保留本地备份

本地删除可同步删除 Github 上面的文件

支持替换数据库中旧的资源链接地址

## 安装

从 Github 下载源码，通过 WordPress 后台上传安装，或者直接将源码上传到 WordPress 插件目录 `wp-content/plugins`，然后在后台启用

Github 项目地址: https://github.com/niqingyang/wp-github-gos

修改配置

方法一：在 WordPress 插件管理页面有设置按钮，进行设置

方法二：在 WordPress 后台管理左侧导航栏设置下 `Github 存储`，点击进入设置页面

特别说明

本插件仅支持PHP 5.4+ 版本

## 插件预览

![](/screenshot-1.png)

## 常见问题

1、怎么替换文章中之前的旧资源地址链接

这个插件已经加上了替换数据库中之前的旧资源地址链接功能，只需要填好对应的链接即可，如图所示

![](/screenshot-2.png)

## WP-Editor.md

如果使用的 Markdown 编辑器是 WP-Editor.md，那么可以通过在主题模板下的 functions.php 文件中加入下面的代码，即可实现在编辑器中粘帖截图上传的功能

```php
/**
 * 文章编辑页面从媒体选择器中插入图片
 *
 * @param string $html
 * @param integer $id
 * @param string $caption
 * @param string $title
 * @param string $align
 * @param string $url
 * @param string $size
 * @param string $alt
 * @return mixed
 */
function acme_image_send_to_editor ($html, $id, $caption, $title, $align, $url, $size, $alt)
{
    $attrs = wp_kses_hair($html, wp_allowed_protocols());

    $attrs = array_column($attrs, 'value', 'name');

    $src = $url . '?w=' . $attrs['width'] . '&h=' . $attrs['height'] . '&id=' . $id;

    $html = str_replace($url, $src, $html);

    return $html;
}

add_filter('image_send_to_editor', 'acme_image_send_to_editor', 10, 8);

/**
 * 粘帖图片上传，替换EditorMD插件的粘帖上传
 */
function acme_imagepaste_action_callback ()
{
    $result = array(
        'error' => ''
    );
    $upload = wp_upload_dir();
    $uploadUrl = $upload['url'];
    $uploadDir = $upload['path'];
    $extension = '';
    list($data, $image) = explode(';', $_REQUEST['dataurl']);
    list($field, $type) = explode(':', $data);
    list($encoding, $content) = explode(',', $image);
    if($type == 'image/png')
    {
        $extension = 'png';
    }
    $name = md5($_REQUEST['dataurl']);
    if(! $extension)
    {
        $result['error'] = "Could not determine image extension type";
    }
    else
    {
        $file = $uploadDir . '/paste-' . $name . '.' . $extension;
        file_put_contents($file, base64_decode($content));
        // 获取图片尺寸
        $size = @getimagesize($file);

        if($size == false)
        {
            $result['error'] = "Could not get image size";
        }
        else
        {
            $_FILES['file'] = [
                'name' => '/paste-' . $name . '.' . $extension,
                'type' => 'image/' . $extension,
                'tmp_name' => $file,
                'error' => 0,
                'size' => filesize($file)
            ];

            $time = current_time('mysql');

            $overrides = array(
                'test_form' => false,
                'action' => 'acme_imagepaste_action'
            );

            $file = wp_handle_upload($_FILES['file'], $overrides, $time);

            if(isset($file['error']))
            {
                $result['error'] = $file['error'];
            }
            else
            {
                if(strpos($file['url'], '?'))
                {
                    $url = $file['url'];
                }
                else
                {
                    $url = $file['url'] . '?w=' . $size[0] . '&h=' . $size[1];
                }

                $result['w3tc'] = 0;
                $result['url'] = $url;
                $result['elementid'] = $_REQUEST['elementid'];
            }
        }
    }

    echo json_encode($result);
    die();
}

add_action('wp_ajax_imagepaste_action', 'acme_imagepaste_action_callback', 1);
```
