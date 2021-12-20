jQuery(
	function( $ ) {
        $('table.table-view-list').find('a.pll_icon_add').each(function($this){
			$link = new URL(this.href);
			$par = $link.searchParams;
			$par.append('action', 'pat_auto_translate');
			$link.pathname = '/wp-admin/admin-post.php'
			$link_str = $link.toString();
			$(this).after('<a href="' + $link_str + '" class="pat_auto_translate_icon"><span class="screen-reader-text">Add auto translation</span>');
		});

		//https://stackoverflow.com/questions/6386128/how-can-i-call-a-function-after-an-element-has-been-created-in-jquery
		var CONTROL_INTERVAL = setInterval(function(){
			// Check if element exist
			if($('td.pll-edit-column').length > 0){
				$('td.pll-edit-column').each(function($this){
					$a = $(this).children('a')[0];
					$link = new URL($a.href);
					$par = $link.searchParams;
					$par.append('action', 'pat_auto_translate');
					$par.append('edit_post', '1');
					$link.pathname = '/wp-admin/admin-post.php'
					$link_str = $link.toString();
					$(this).after('<td class="pll-edit-column pll-column-icon"><a href="' + $link_str + '" class="pat_auto_translate_icon pat_auto_translate_icon_small"><span class="screen-reader-text">Add auto translation</span></td>');
				});
				clearInterval(CONTROL_INTERVAL);
			}
		}, 500);
	}
);