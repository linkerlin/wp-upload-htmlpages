<?php
/*
Plugin Name: HTML Pages
Description: 在后台上传单个 HTML 文件，即可通过 /htmlpages/文件名 直接访问。上传的文件保存在网站根目录 /htmlpages/ 下。
Version:     1.0
Author:      Steper Lin
*/

// 禁止直接访问
defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// 1. 在左侧菜单“工具”下增加子菜单
// ------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_submenu_page(
        'tools.php',                 // 父菜单
        'HTML Pages',                // 页面标题
        'HTML Pages',                // 菜单标题
        'manage_options',            // 权限
        'htmlpages',                 // slug
        'htmlpages_admin_page'       // 回调
    );
} );

// ------------------------------------------------------------------
// 2. 后台页面表单 + 处理上传
// ------------------------------------------------------------------
function htmlpages_handle_upload() {
    if ( ! isset( $_POST['htmlpages_nonce'] ) || ! wp_verify_nonce( $_POST['htmlpages_nonce'], 'htmlpages_upload' ) ) {
        return '';
    }

    if ( ! isset( $_FILES['htmlfile'] ) || $_FILES['htmlfile']['error'] !== UPLOAD_ERR_OK ) {
        return '';
    }

    $file = $_FILES['htmlfile'];

    // 仅允许 .html / .htm
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'html', 'htm' ], true ) ) {
        return '<div class="notice notice-error"><p>仅支持 .html / .htm 文件！</p></div>';
    }

    // 目标目录：站点根目录 /htmlpages/
    $dir = untrailingslashit( ABSPATH ) . '/htmlpages';
    htmlpages_ensure_dir(); // 确保目录和 index.php 存在

    // 清理文件名
    $filename = sanitize_file_name( $file['name'] );
    $dest     = $dir . '/' . $filename;

    // 处理覆盖选项
    $overwrite = isset( $_POST['htmlpages_overwrite'] ) && $_POST['htmlpages_overwrite'] === '1';
    if ( file_exists( $dest ) && ! $overwrite ) {
        return '<div class="notice notice-warning"><p>文件已存在，如需覆盖请勾选“覆盖同名文件”选项。</p></div>';
    }

    if ( move_uploaded_file( $file['tmp_name'], $dest ) ) {
        $url = home_url( 'htmlpages/' . $filename );

        // 收集选项
        $options = [
            'post_type'      => isset( $_POST['htmlpages_post_type'] ) ? sanitize_key( $_POST['htmlpages_post_type'] ) : 'post',
            'content_display' => isset( $_POST['htmlpages_content_display'] ) ? sanitize_key( $_POST['htmlpages_content_display'] ) : 'link',
            'post_category'  => isset( $_POST['post_category'] ) ? array_map( 'intval', $_POST['post_category'] ) : [],
        ];

        htmlpages_auto_post( $dest, $filename, $options );

        return '<div class="notice notice-success"><p>上传成功！<br>访问地址：<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a></p></div>';
    } else {
        return '<div class="notice notice-error"><p>移动文件失败，请检查 /htmlpages/ 目录权限。</p></div>';
    }
}

function htmlpages_admin_page() {
    // 处理上传并获取消息
    $msg = htmlpages_handle_upload();

    // 显示已上传文件
    $dir   = untrailingslashit( ABSPATH ) . '/htmlpages';
    $files = is_dir( $dir ) ? array_diff( scandir( $dir ), [ '.', '..' ] ) : [];

    ?>
    <div class="wrap">
        <h1>上传 HTML 页面</h1>
        <?php echo $msg; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'htmlpages_upload', 'htmlpages_nonce' ); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="htmlfile">选择 HTML 文件</label></th>
                        <td><input type="file" name="htmlfile" id="htmlfile" accept=".html,.htm" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="htmlpages_overwrite">覆盖同名文件</label></th>
                        <td><input type="checkbox" name="htmlpages_overwrite" id="htmlpages_overwrite" value="1">
                            <p class="description">如果 /htmlpages/ 目录下已存在同名文件，选中此项以覆盖。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="htmlpages_post_type">发布为</label></th>
                        <td>
                            <select name="htmlpages_post_type" id="htmlpages_post_type">
                                <option value="post" selected="selected">文章 (Post)</option>
                                <option value="page">页面 (Page)</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="htmlpages-post-category-row">
                        <th scope="row"><label>文章分类</label></th>
                        <td>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 5px;">
                                <?php
                                wp_category_checklist();
                                ?>
                            </div>
                            <p class="description">为自动创建的文章选择一个或多个分类。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="htmlpages_content_display">内容显示方式</label></th>
                        <td>
                            <select name="htmlpages_content_display" id="htmlpages_content_display">
                                <option value="link" selected="selected">链接</option>
                                <option value="iframe">iFrame 嵌入</option>
                            </select>
                            <p class="description">自动创建的文章/页面内容是显示一个可点击的URL，还是直接嵌入整个HTML页面。</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button('上传'); ?>
        </form>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function toggleCategoryRow() {
                    if ($('#htmlpages_post_type').val() === 'post') {
                        $('#htmlpages-post-category-row').show();
                    } else {
                        $('#htmlpages-post-category-row').hide();
                    }
                }
                toggleCategoryRow();
                $('#htmlpages_post_type').on('change', toggleCategoryRow);
            });
        </script>

        <?php if ( $files ) : ?>
            <h2>已上传的文件</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col">文件名</th>
                        <th scope="col">访问 URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $files as $f ) :
                        if ( $f === 'index.php' ) continue;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $f ); ?></td>
                        <td>
                            <?php
                            $url = home_url( 'htmlpages/' . $f );
                            echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// ------------------------------------------------------------------
// 3. 注册重写规则，把 /htmlpages/文件名 映射到实际文件
// ------------------------------------------------------------------
add_action( 'init', function () {
    // 让 /htmlpages/xxx.html 不经过 WP 查询直接访问静态文件
    add_rewrite_rule(
        '^htmlpages/(.+?\.(?:html|htm))?$',
        'index.php?htmlpages_file=$matches[1]',
        'top'
    );
    // 刷新规则一次即可，插件启用时自动完成（见下方 register_activation_hook）
} );

// 让 WP 识别自定义查询变量
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'htmlpages_file';
    return $vars;
} );

// 拦截请求，直接读取静态文件并输出
add_action( 'template_redirect', function () {
    $file = get_query_var( 'htmlpages_file' );
    if ( $file ) {
        $path = untrailingslashit( ABSPATH ) . '/htmlpages/' . sanitize_file_name( $file );
        if ( file_exists( $path ) && is_file( $path ) ) {
            status_header( 200 );
            header( 'Content-Type: text/html; charset=utf-8' );
            readfile( $path );
            exit;
        }
    }
} );

// ------------------------------------------------------------------
// 4. 插件启用时刷新重写规则
// ------------------------------------------------------------------
register_activation_hook( __FILE__, function () {
    htmlpages_ensure_dir();
    flush_rewrite_rules();
} );

// 插件停用时再次刷新，移除规则
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// 如果目录不存在就创建，并放置一个 index.php 防止目录浏览
function htmlpages_ensure_dir() {
    $dir = untrailingslashit( ABSPATH ) . '/htmlpages';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    // Add an empty index.php to prevent directory listing
    if ( ! file_exists( $dir . '/index.php' ) ) {
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
    }
}

/* ------------------------------------------------------------------
 * 5. 上传后自动发帖（标题=html<title>，内容=可点击URL）
 *    用 WP_Query 取代已弃用的 get_page_by_title
 * ------------------------------------------------------------------ */
function htmlpages_auto_post( $filepath, $filename, $options = [] ) {
    // 默认选项
    $defaults = [
        'post_type'      => 'post',
        'content_display' => 'link',
        'post_category'  => [],
    ];
    $opts = wp_parse_args( $options, $defaults );

    // 1. 读取 <title>
    $html = file_get_contents( $filepath );
    preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m );
    $title = empty( $m[1] ) ? $filename : wp_strip_all_tags( $m[1] );

    // 2. 目标 URL
    $url = home_url( 'htmlpages/' . $filename );

    // 3. 检查同名标题是否已存在
    $query_args = [
        'post_type'              => $opts['post_type'],
        'title'                  => $title,
        'posts_per_page'         => 1,
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    // 如果是页面，查询参数不同
    if ( $opts['post_type'] === 'page' ) {
        unset( $query_args['title'] );
        $query_args['post_title_like'] = $title;
    }

    $exists = new WP_Query( $query_args );

    if ( ! $exists->have_posts() ) {
        // 4. 根据选项生成内容
        if ( $opts['content_display'] === 'iframe' ) {
            $content = sprintf( '<iframe src="%1$s" style="width:100%%; height:800px; border:none;"></iframe>', esc_url( $url ) );
        } else {
            $content = sprintf( '<a href="%1$s" target="_blank" rel="noopener">%1$s</a>', esc_url( $url ) );
        }

        // 5. 准备文章数据
        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => $opts['post_type'],
        ];

        // 如果是文章且有分类，则添加分类
        if ( $opts['post_type'] === 'post' && ! empty( $opts['post_category'] ) ) {
            $post_data['post_category'] = $opts['post_category'];
        }

        wp_insert_post( $post_data );
    }
}
