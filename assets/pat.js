jQuery(
	function( $ ) {

		function pat_add_autotranslate_link(referer, classes){
			link = new URL(referer.href);
			par = link.searchParams;
			par.append('action', 'pat_auto_translate');
			par.append('ref_path', link.pathname);
			link.pathname = '/wp-admin/admin-post.php';
			link_str = link.toString();
			return '<a href="' + link_str + '" class="' + classes + '"><span class="screen-reader-text">Add auto translation</span>';
		}

		//add auto-translate icons on post, pages, products, categories, etc list page
		//https://stackoverflow.com/questions/4728144/check-variable-equality-against-a-list-of-values
		if ( ['wp-admin/edit.php', 'wp-admin/edit-tags.php'].indexOf(location.pathname.substr(1)) > -1){
			$('table.table-view-list').find('a.pll_icon_add').each(function($this){
				link = pat_add_autotranslate_link (this, 'pat_auto_translate_icon pat_auto_translate_ajax');
				$(this).after( link );
			} );
		}

		//add auto-translate icons on post-edit page
		//https://stackoverflow.com/questions/6386128/how-can-i-call-a-function-after-an-element-has-been-created-in-jquery
		if (location.pathname.substr(1) == 'wp-admin/post.php'){
			var CONTROL_INTERVAL = setInterval(function(){
				// Check if element exist
				if($('td.pll-edit-column').length > 0){
					$('td.pll-edit-column').find('a').each(function($this){			//.each(function($this){
						$link = new URL(this.href);
						if ($link.pathname == '/wp-admin/post-new.php' || this.classList.contains('pll_icon_add')){
							link = pat_add_autotranslate_link (this, 'pat_auto_translate_icon pat_auto_translate_icon_small');
							$(this.parentElement).after('<td class="pll-edit-column pll-column-icon">' + link + '</td>');
						} else {
							$(this.parentElement).after('<td class="pll-edit-column pll-column-icon"></td>');
						}
	
					});
					clearInterval(CONTROL_INTERVAL);
				}
			}, 500);
		}

		//ajaxify translating in table view
		$('#posts-filter').submit(function(e){
			e.preventDefault();
			
			if (e.target.action.value == 'pat_mass_translate'){
				hrefs = $(e.target).find('input[type=checkbox]:checked').parent().parent().find('a.pat_auto_translate_ajax');
				pat_multi_translate_ajax(hrefs);
			} else {
				//submit original form
				e.target.submit();
			}
		})

		//ajaxify single post
		$(document).on('click', 'a.pat_auto_translate_ajax', function(e){
			e.preventDefault();
			//and for single translate this
			hrefs = [$(this)];
			pat_multi_translate_ajax(hrefs);
			return false;
		});
		
		function pat_multi_translate_ajax(hrefs){
			//now we can construct parameter array	
			parameter_array = [];
			c_total = 0;
			counter = 0;
			global_post_type = '';
			global_taxonomy = '';

			//this is the modal windows to display translation progress
			tb_show("Polylang Google API translate - processing translations","#TB_inline?height=70%&amp;width=50%&amp;inlineId=pat_thickbox&amp;modal=true",null);
			$('#pat_tb_messages').empty();
			$('span.pat_spinner').css('visibility', 'visible');
			promises = [];			//this is an array for holding ajax calls
			$batch_message = '';

			for (single_href of hrefs){
				c_total ++;
				counter ++;

				link = new URL($(single_href).attr('href'));
				par = link.searchParams;

				//check if we are dealing with consistent data
				//for the first value save post_type and taxonomy
				if(c_total == 1){
					global_post_type = par.get('post_type');
					global_taxonomy = par.get('taxonomy');
				//for remaining values - check if they don't differ from the first one
				} else if ( (global_post_type != null && global_post_type != par.get('post_type'))
						|| (global_taxonomy != null && global_taxonomy != par.get('taxonomy')) ){
					$('#pat_tb_messages').empty();
					$('#pat_tb_messages').append('<div> Please select the same type of content to translate. Mixed content type translation is not supported. </div>');
					$('#pat_tb_messages').animate({ scrollTop: $('#pat_tb_messages div:last-child').offset().top }, 500);
					$('#TB_closeWindowButton').prop('disabled', false);
					$('span.pat_spinner').css('visibility', 'hidden');
					return;
				}

				parameter_array.push({
					from_post: par.get('from_post'),
					new_lang: par.get('new_lang'),
					from_tag: par.get('from_tag'),
					ref_row_id: "#" + $(this).closest('tr').attr('id'),
				});

				$batch_message += ' (from id: ' + par.get('from_post') + par.get('from_tag') + ' to lang: ' + par.get('new_lang') + ') ';

				if (counter == pat_js_obj.pat_batch_size || c_total == hrefs.length){
					
					$('#pat_tb_messages').append('<div>Sending batch for translation: ' + $batch_message + '</div>');
					$('#pat_tb_messages').animate({ scrollTop: $('#pat_tb_messages div:last-child').offset().top }, 500);

					$batch_message = '';	//reset message about batch sending

					request = $.ajax({
						url: pat_js_obj.ajaxurl,
						method: "POST",
						data: {
							'action': 'pat_auto_translate',
							'nonce' : pat_js_obj.nonce,
							'parameter_array': JSON.stringify(parameter_array),
							'post_type': global_post_type,
							'taxonomy': global_taxonomy
						},
						success:function( response ) {
							if ( response ) {
								var res = wpAjax.parseAjaxResponse( response, 'ajax-response' );
								$.each(
									res.responses,
									function() {
										if ( 'row' == this.what ) {
											if ($( this.supplemental.row_id ).length > 0){
												$( this.supplemental.row_id ).replaceWith( this.data );
											} else {
												$( this.supplemental.ref_row_id ).after(this.data);
											}
											if ($( this.supplemental.row_id ).length > 0){
												$( this.supplemental.row_id ).find('a.pll_icon_add').each(function($this){
													link = pat_add_autotranslate_link (this, 'pat_auto_translate_icon pat_auto_translate_ajax');
													$(this).after( link );
												} );
											}
										} else if ( 'message' == this.what ) {
											$('#pat_tb_messages').append('<div>' + this.data + '</div>');
											$('#pat_tb_messages').animate({ scrollTop: $('#pat_tb_messages div:last-child').offset().top }, 500);
										}
							});}
						},
						error: function(errorThrown){
							$('#pat_tb_messages').append('<div>' + errorThrown + '</div>');
							$('#pat_tb_messages').animate({ scrollTop: $('#pat_tb_messages div:last-child').offset().top }, 500);
							//$(this).removeClass('blink');
							$(this).addClass('error');
						}
					});
					promises.push( request);
					counter = 0;
					parameter_array = [];
				}
			}
			//https://stackoverflow.com/questions/20291366/how-to-wait-until-jquery-ajax-request-finishes-in-a-loop
			//https://stackoverflow.com/questions/16956116/is-it-possible-to-run-code-after-all-ajax-call-completed-under-the-for-loop-stat
			$.when.apply($, promises).done(function(){
				$('#pat_tb_messages').append('<div> All Translations are finished. Please review them and publish. </div>');
				$('#pat_tb_messages').animate({ scrollTop: $('#pat_tb_messages div:last-child').offset().top }, 500);
				$('#TB_closeWindowButton').prop('disabled', false);
				$('span.pat_spinner').css('visibility', 'hidden');
			 })
		}

		//functions for string translations
		$('textarea[name^="translation["], input[name^="translation["]').each(function($this){
			$link = new URL(window.location.href);
			$par = $link.searchParams;
			$par.append('action', 'pat_string_translate');
			$par.append('to_lang', $(this).attr('name').substring(12,14));
			$link.pathname = '/wp-admin/admin-post.php';
			$link_str = $link.toString();
			$(this).after('<a href="' + $link_str + '" class="pat_string_translate pat_auto_translate_icon"><span class="screen-reader-text">Add auto translation</span>');
		});

		$('a.pat_string_translate').click(function(e){
			e.preventDefault();

			$string = $(this).closest('tr').find('td.string')[0].innerText;
			$link = new URL($(this).attr('href'));
			$par = $link.searchParams;
			$to_lang = $par.get('to_lang');
			$(this).addClass('blink');

			$.ajax({
				url: pat_js_obj.ajaxurl,
				method: "POST",
				context: this,
				data: {
					'action': 'pat_string_translate',
					'nonce' : pat_js_obj.nonce,
					'string_to_translate': $string,
					'to_lang': $to_lang,
				},
				success:function(data) {
					$(this).siblings('textarea[name^="translation["], input[name^="translation["]').val(data);
					$(this).removeClass('blink');
				},
				error: function(errorThrown){
					console.log(errorThrown);
					$(this).removeClass('blink');
					$(this).addClass('error');
				}
			});  
		});


	}
);
