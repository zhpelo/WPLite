<?php if ( ! woodmart_get_opt( 'white_label' ) ) : ?>
	<div class="xts-footer xts-theme-style">
		<div class="xts-row">
			<div class="xts-col">
				<a class="xts-logo" href="https://www.shopagg.com/" target="_blank">
					<img src="<?php echo esc_url( WOODMART_ASSETS_IMAGES . '/shopagg-logo-b.png' ); ?>" alt="<?php esc_attr_e( 'SHOPAGG主题', 'woodmart' ); ?>">
				</a>
			</div>

			<div class="xts-col-auto">
				<?php
				new XTS\Admin\Modules\Dashboard\Menu(
					[
						'items' => [
							[
								'link' => [
									'url'        => 'https://www.shopagg.com/app-store/shopagg-woo-theme',
									'new_window' => true,
								],
								'icon' => 'documentation',
								'text' => esc_html__( 'Documentation', 'woodmart' ),
							],
							[
								'link' => [
									'url'        => 'https://www.shopagg.com/courses',
									'new_window' => true,
								],
								'icon' => 'video-tutorials',
								'text' => esc_html__( 'Video tutorials', 'woodmart' ),
							],
							[
								'link' => [
									'url'        => 'https://work.weixin.qq.com/kfid/kfcfb166e1c6c9ac920',
									'new_window' => true,
								],
								'icon' => 'rate-theme',
								'text' => esc_html__( 'WeChat Support', 'woodmart' ),
							],
							[
								'link' => [
									'url'        => 'https://forum.shopagg.com',
									'new_window' => true,
								],
								'icon' => 'support-forum',
								'text' => esc_html__( 'Support forum', 'woodmart' ),
							],
						],
					]
				);
				?>
			</div>
		</div>
	</div>
<?php endif; ?>
