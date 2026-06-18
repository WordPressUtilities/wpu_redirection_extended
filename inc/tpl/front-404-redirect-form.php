<?php
defined('ABSPATH') || die;

/* Minimal redirect-creation modal shown in the footer of 404 pages.
   Runs in WPURedirectionExtended::display_404_redirect_form() scope ($source and $this available). */

echo '<div id="wpu-redir-ext-404-modal" style="position:fixed;left:20px;bottom:20px;z-index:99999;width:400px;max-width:calc(100vw - 40px);box-sizing:border-box;padding:20px;background:#1d2327;color:#fff;font:14px/1.4 sans-serif;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.4)">';
echo '<button type="button" onclick="document.getElementById(\'wpu-redir-ext-404-modal\').remove()" aria-label="' . esc_attr__('Close', 'wpu_redirection_extended') . '" style="position:absolute;top:8px;right:8px;width:28px;height:28px;padding:0;border:0;background:transparent;color:#fff;font-size:20px;line-height:1;cursor:pointer">&times;</button>';
echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;flex-direction:column;gap:10px">';
echo '<input type="hidden" name="action" value="wpu_redir_ext_create_404" />';
echo wp_nonce_field('wpu_redir_ext_create_404', '_wpnonce', true, false);
echo '<strong style="font-size:15px">' . esc_html__('Create a redirection', 'wpu_redirection_extended') . '</strong>';
echo '<input type="text" name="source" value="' . esc_attr($source) . '" readonly style="padding:6px 8px;box-sizing:border-box" />';
echo '<input type="url" name="target" required placeholder="' . esc_attr__('Target URL', 'wpu_redirection_extended') . '" style="padding:6px 8px;box-sizing:border-box" />';

if (!empty($suggestions)) {
    echo '<div style="display:flex;flex-direction:column;gap:4px">';
    echo '<div style="font-size:12px;opacity:.7">' . esc_html__('Suggestions', 'wpu_redirection_extended') . '</div>';
    foreach ($suggestions as $suggestion) {
        $abs = esc_attr(home_url($suggestion['url']));
        echo '<button type="button" class="wpu-redir-ext-suggestion" data-target="' . $abs . '" style="text-align:left;padding:0.2em 0.4em;font-size: 0.9em;border:1px solid #50575e;background:#2c3338;color:#fff;border-radius:4px;cursor:pointer">' . esc_html($suggestion['url']) . '</button>';
    }
    echo '</div>';
}

echo '<button type="submit" class="button button-primary" style="padding:6px 12px;cursor:pointer;color:#000 !important">' . esc_html__('Redirect', 'wpu_redirection_extended') . '</button>';
echo '</form>';

echo '<script>(function(){var m=document.getElementById("wpu-redir-ext-404-modal");if(!m){return;}m.querySelectorAll(".wpu-redir-ext-suggestion").forEach(function(b){b.addEventListener("click",function(){var t=m.querySelector("[name=target]");if(t){t.value=this.getAttribute("data-target");t.focus();}});});})();</script>';

echo '</div>';
