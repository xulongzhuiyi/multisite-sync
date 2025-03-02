jQuery(document).ready(function ($) {
    console.log('admin.js loaded');
    // 单篇同步按钮点击事件
    $('.sync-button').on('click', function () {
        console.log('sync-button clicked');
        let postId = $(this).data('post-id');
        startSync(postId);
    });
    // 批量同步按钮点击事件
    $('#bulk-sync-button').on('click', function () {
        let postIds = [];
        $('input[name="post[]"]:checked').each(function () {
            postIds.push($(this).val());
        });
        if (postIds.length === 0) {
            alert('请选择要同步的文章！');
            return;
        }
        // 获取选中的目标站点（侧边栏复选框）
        let selectedLangs = [];
        $('input[name="ms_sync_sites[]"]:checked').each(function () {
            selectedLangs.push($(this).val());
        });
        if (selectedLangs.length === 0) {
            alert('请选择要同步的站点！');
            return;
        }
        // 发送批量同步 AJAX 请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ms_sync_bulk',
                security: ms_sync_vars.nonce,
                post_ids: postIds,
                langs: selectedLangs
            },
            // 修改成功提示显示实际同步的站点
            success: function (response) {
                if (response.success) {
                    let sites = selectedLangs.map(lang => {
                        return $('input[name="ms_sync_sites[]"][value="' + lang + '"]')
                            .parent().text().trim();
                    }).join(', ');
                    alert('同步成功到: ' + sites);
                } else {
                    alert('同步失败: ' + response.data.message);
                }
            },
            error: function () {
                alert('批量同步请求失败，请检查控制台日志');
            }
        });
    });
    // 定义单篇文章同步函数
    function startSync(postId) {
        // 获取选中的目标站点复选框
        let selectedLangs = [];
        $('input[name="ms_sync_sites[]"]:checked').each(function () {
            selectedLangs.push($(this).val());
        });
        if (selectedLangs.length === 0) {
            alert('请选择要同步的站点！');
            return;
        }
        // 发送单篇同步 AJAX 请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ms_sync_post',
                security: ms_sync_vars.nonce,
                post_id: postId,
                langs: selectedLangs,
                force_sync: false
            },
            success: function (response) {
                if (response.success) {
                    alert('同步成功: ' + response.data.message);
                } else {
                    alert('同步失败: ' + response.data.message);
                }
            },
            error: function (xhr, status, error) {
                console.error('同步请求失败:', error);
                alert('同步请求失败，请检查控制台日志');
            }
        });
    }


    // 添加批量操作按钮
    jQuery(document).ready(function ($) {
        $('.wp-list-table').each(function () {
            $(this).find('thead tr, tfoot tr').append('<th><input type="checkbox" class="ms-bulk-select-all"></th>');
            $(this).find('tbody tr').each(function () {
                $(this).append('<td><input type="checkbox" class="ms-bulk-select" value="' + $(this).data('id') + '"></td>');
            });
        });
        // 添加批量操作菜单
        $('.bulkactions').append('<button type="button" class="button ms-bulk-sync">批量同步</button>');
    });

    // 分类/标签页同步按钮事件
    jQuery(document).on('click', '.sync-taxonomy-button', function () {
        const $btn = jQuery(this);
        const term_id = $btn.data('term-id');
        const taxonomy = $btn.data('taxonomy');
        const lang = $btn.data('lang');
        $btn.prop('disabled', true).text('同步中...');
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ms_sync_taxonomy',
                security: ms_sync_vars.nonce,
                term_id: term_id,
                taxonomy: taxonomy,
                lang: lang
            },
            success: function (response) {
                if (response.success) {
                    alert('同步成功：' + response.data.message);
                } else {
                    alert('同步失败：' + response.data.message);
                }
            },
            error: function (xhr) {
                alert('请求失败，状态码：' + xhr.status);
            },
            complete: function () {
                $btn.prop('disabled', false).text('立即同步');
            }
        });
    });
});
