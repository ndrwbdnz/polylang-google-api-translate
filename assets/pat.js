jQuery(
	function( $ ) {
        $('a.pll_icon_add').each(function($this){
			$link = new URL(this.href);
			$par = $link.searchParams;
			$par.append('auto_translate', '1');
			$link_str = $link.toString();
			$(this).after('<a href="' + $link_str + '" class="pat_auto_translate_icon"><span class="screen-reader-text">Add auto translation</span></div>');
		});
		
	}
);
