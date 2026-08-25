<?php
 
	/**
	 * COMMENTS TEMPLATE
	 */

	/*if('comments.php' == basename($_SERVER['SCRIPT_FILENAME']))
		die(__('Please do not load this page directly.', 'akina'));*/

	if(post_password_required()){
		return;
	}

?>

	<?php if(comments_open()): ?>

	<section id="comments" class="comments">

		<div class="commentwrap comments-hidden">
			<div class="notification"><i class="iconfont icon-mark"></i><?php _e('view comments', 'sakura'); /*查看评论*/?> -
			<span class="noticom"><?php comments_number('NOTHING', '1'.__(" comment","sakura"), '%'.__(" comments","sakura")); ?> </span>
			</div>
		</div>

		<div class="comments-main">
		 <h3 id="comments-list-title">Comments | <span class="noticom"><?php comments_number('NOTHING', '1'.__(" comment","sakura"), '%'.__(" comments","sakura")); ?> </span></h3> 
		<div id="loading-comments"><span></span></div>
			<?php if(have_comments()): ?>

				<ul class="commentwrap">
					<?php wp_list_comments('type=comment&callback=akina_comment_format'); ?>	
				</ul>

          <nav id="comments-navi">
				<?php paginate_comments_links('prev_text=« Older&next_text=Newer »');?>
			</nav>

			 <?php else : ?>

				<?php if(comments_open()): ?>
					<div class="commentwrap">
						<div class="notification-hidden"><i class="iconfont icon-mark"></i> <?php _e('no comment', 'sakura'); /*暂无评论*/?></div>
					
					</div>
				<?php endif; ?>

			<?php endif; ?>

			<?php
				$robot_comments = '';
				if(comments_open()){
					if(akina_option('norobot')) $robot_comments = '<label class="siren-checkbox-label"><input class="siren-checkbox-radio" type="checkbox" name="no-robot"><span class="siren-no-robot-checkbox siren-checkbox-radioInput"></span>'.__('I\'m not a robot', 'sakura').'</label>';
				$private_ms = akina_option('open_private_message') ? '<label class="siren-checkbox-label"><input class="siren-checkbox-radio" type="checkbox" name="is-private"><span class="siren-is-private-checkbox siren-checkbox-radioInput"></span>'.__('Comment in private', 'sakura').'</label>' : '';
				$mail_notify = akina_option('mail_notify') ? '<label class="siren-checkbox-label"><input class="siren-checkbox-radio" type="checkbox" name="mail-notify"><span class="siren-mail-notify-checkbox siren-checkbox-radioInput"></span>'.__('Comment reply notify', 'sakura').'</label>' : '';
				$comment_fields = array(
					'author' =>
						'<div class="popup cmt-popup cmt-author" onclick="cmt_showPopup(this)"><span class="popuptext" id="thePopup" style="margin-left: -115px;width: 230px;">' . __("Auto pull nickname and avatar with a QQ num. entered", "sakura")/*输入QQ号将自动拉取昵称和头像*/ . '</span><input type="text" placeholder="' . __("Nickname or QQ number", "sakura") /*昵称或QQ号*/. ' ' . ( $req ?  '(' . __("Name* ", "sakura") . ')' : '') . '" name="author" id="author" value="' . esc_attr($comment_author) . '" size="22" autocomplete="off" tabindex="1" ' . ($req ? "aria-required='true'" : '' ). ' /></div>',
					'email' =>
						'<div class="popup cmt-popup" onclick="cmt_showPopup(this)"><span class="popuptext" id="thePopup" style="margin-left: -65px;width: 130px;">' . __("You will receive notification by email", "sakura")/*你将收到回复通知*/ . '</span><input type="text" placeholder="' . __("email", "sakura") . ' ' . ( $req ? '(' . __("Must* ", "sakura") . ')' : '') . '" name="email" id="email" value="' . esc_attr($comment_author_email) . '" size="22" tabindex="1" autocomplete="off" ' . ($req ? "aria-required='true'" : '' ). ' /></div>',
					'url' =>
						'<div class="popup cmt-popup" onclick="cmt_showPopup(this)"><span class="popuptext" id="thePopup" style="margin-left: -55px;width: 110px;">' . __("Advertisement is forbidden 😀", "sakura")/*禁止小广告😀*/ . '</span><input type="text" placeholder="' . __("Site", "sakura") . '" name="url" id="url" value="' . esc_attr($comment_author_url) . '" size="22" autocomplete="off" tabindex="1" /></div>' . $robot_comments . $private_ms . $mail_notify
				);
				if ( ! is_user_logged_in() ) {
					$comment_fields = array(
						'avatar' => '<div class="cmt-info-container"><div class="comment-user-avatar"><img src="' . get_template_directory_uri() . '/images/avatar.jpeg"><div class="socila-check qq-check"><i class="fa fa-qq" aria-hidden="true"></i></div><div class="socila-check gravatar-check"><i class="fa fa-google" aria-hidden="true"></i></div></div>',
					) + $comment_fields + array(
						'qq' => '<input type="text" placeholder="QQ" name="new_field_qq" id="qq" value="' . esc_attr($comment_author_url) . '" style="display:none" autocomplete="off"/><!--此栏不可见--></div>',
					);
				}
				$args = array(
						'id_form' => 'commentform',
						'id_submit' => 'submit',
						'title_reply' => '',
						'title_reply_to' => '<div class="graybar"><i class="fa fa-comments-o"></i>' . __('Leave a Reply to', 'sakura') . ' %s' . '</div>',
						'cancel_reply_link' => __('Cancel Reply', 'sakura'),
						'label_submit' => __('BiuBiuBiu~', 'sakura'),
						'submit_button' => '<input name="%1$s" type="submit" id="%2$s" class="%3$s" value="%4$s" title="' . esc_attr__( 'Submit comment', 'sakura' ) . '" aria-label="' . esc_attr__( 'Submit comment', 'sakura' ) . '" />',
						'submit_field' => '<p class="form-submit"><span class="submit-comment-tips popup"><span class="submit-comment-popuptext">' . esc_html__( 'Submit comment', 'sakura' ) . '</span>%1$s</span>%2$s</p>',
						'comment_field' => '<p style="font-style:italic"><a href="https://segmentfault.com/markdown" target="_blank"><i class="iconfont icon-markdown" style="color:#000"></i></a> Markdown Supported while <i class="fa fa-code" aria-hidden="true"></i> Forbidden</p><div class="comment-textarea"><textarea placeholder="' . __("You are a surprise that I will only meet once in my life", "sakura") . ' ..." name="comment" class="commentbody" id="comment" rows="5" tabindex="4"></textarea><label class="input-label">' . __("You are a surprise that I will only meet once in my life", "sakura") . ' ...</label></div>
                        <div id="upload-img-show"></div>
                        <!--插入表情面版-->
                        <p id="emotion-toggle" class="no-select">
                            <span class="emotion-toggle-off">' . __("Click me OωO", "sakura")/*戳我试试 OωO*/ . '</span>
                            <span class="emotion-toggle-on">' . __("Woooooow ヾ(≧∇≦*)ゝ", "sakura")/*嘿嘿嘿 ヾ(≧∇≦*)ゝ*/ . '</span>
                        </p>
                        <div class="emotion-box no-select">
                            <table class="motion-switcher-table">
                                <tr>
                                    <th onclick="motionSwitch(\'.bili\')" 
                                        class="bili-bar on-hover">bilibili~</th>
                                    <th onclick="motionSwitch(\'.menhera\')"
                                        class="menhera-bar">(=・ω・=)</th>
                                    <th onclick="motionSwitch(\'.tieba\')"
                                        class="tieba-bar">Tieba</th>
                                </tr>
                            </table>
                            <div class="bili-container motion-container">' . push_bili_smilies() . '</div>
                            <div class="menhera-container motion-container" style="display:none;">
                                '.push_emoji_panel().'
                            </div>
                            <div class="tieba-container motion-container" style="display:none;">' . push_smilies() . '</div>
                        </div>
                        <!--表情面版完-->',
						'comment_notes_after' => '',
						'comment_notes_before' => '',
						'fields' => apply_filters( 'comment_form_default_fields', $comment_fields )
					);
					comment_form($args);
				}

			?>

		</div>


	</section>
<?php endif; ?>
