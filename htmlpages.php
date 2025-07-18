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
function htmlpages_admin_page() {
    $msg = '';

    // 处理上传
    if ( isset( $_POST['htmlpages_nonce'] ) && wp_verify_nonce( $_POST['htmlpages_nonce'], 'htmlpages_upload' ) ) {
        if ( isset( $_FILES['htmlfile'] ) && $_FILES['htmlfile']['error'] === UPLOAD_ERR_OK ) {
            $file = $_FILES['htmlfile'];

            // 仅允许 .html / .htm
            $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, [ 'html', 'htm' ], true ) ) {
                $msg = '<div class="notice notice-error"><p>仅支持 .html / .htm 文件！</p></div>';
            } else {
                // 目标目录：站点根目录 /htmlpages/
                $dir = untrailingslashit( ABSPATH ) . '/htmlpages';
                if ( ! is_dir( $dir ) ) {
                    wp_mkdir_p( $dir );
                }

                // 清理文件名
                $filename = sanitize_file_name( $file['name'] );
                $dest     = $dir . '/' . $filename;

                if ( move_uploaded_file( $file['tmp_name'], $dest ) ) {
		    $url = home_url( 'htmlpages/' . $filename );
                    /* ↓ 新增：自动发帖 ↓ */
                    htmlpages_auto_post( $dest, $filename );
                    /* ↑ 新增结束 ↑ */
                    $msg = '<div class="notice notice-success"><p>上传成功！<br>访问地址：<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a></p></div>';
                } else {
                    $msg = '<div class="notice notice-error"><p>移动文件失败，请检查 /htmlpages/ 目录权限。</p></div>';
                }
            }
        }
    }

    // 显示已上传文件
    $dir   = untrailingslashit( ABSPATH ) . '/htmlpages';
    $files = is_dir( $dir ) ? array_diff( scandir( $dir ), [ '.', '..' ] ) : [];

    ?>
    <div class="wrap">
        <h1>上传 HTML 页面</h1>
        <?php echo $msg; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'htmlpages_upload', 'htmlpages_nonce' ); ?>
            <p>
                <label>选择 HTML 文件：</label><br>
                <input type="file" name="htmlfile" accept=".html,.htm" required>
            </p>
            <p>
                <input type="submit" class="button button-primary" value="上传">
            </p>
        </form>

        <?php if ( $files ) : ?>
            <h2>已上传的文件</h2>
            <ul>
            <?php foreach ( $files as $f ) : ?>
                <li><a href="<?php echo esc_url( home_url( 'htmlpages/' . $f ) ); ?>" target="_blank"><?php echo esc_html( $f ); ?></a></li>
            <?php endforeach; ?>
            </ul>
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

// 如果目录不存在就创建
function htmlpages_ensure_dir() {
    $dir = untrailingslashit( ABSPATH ) . '/htmlpages';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
}

/* ------------------------------------------------------------------
 * 5. 上传后自动发帖（标题=html<title>，内容=可点击URL）
 *    用 WP_Query 取代已弃用的 get_page_by_title
 * ------------------------------------------------------------------ */
function htmlpages_auto_post( $filepath, $filename ) {
    // 1. 读取 <title>
    $html = file_get_contents( $filepath );
    preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m );
    $title = empty( $m[1] ) ? $filename : wp_strip_all_tags( $m[1] );

    // 2. 目标 URL
    $url = home_url( 'htmlpages/' . $filename );

    // 3. 检查同名标题是否已存在（WP_Query 方式）
    $exists = new WP_Query( [
        'post_type'              => 'post',
        'title'                  => $title,
        'posts_per_page'         => 1,
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ] );

    if ( ! $exists->have_posts() ) {
        // 4. 生成可点击的链接
        $content = sprintf( '<a href="%1$s" target="_blank" rel="noopener">%1$s</a>', esc_url( $url ) );

        wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ] );
    }
}
