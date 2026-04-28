<?php
/**
 * Directory grid card.
 *
 * Variables in scope (from Campaign_Card_Renderer::render):
 *   array $data {
 *     int    campaign_id
 *     string permalink
 *     string title
 *     string excerpt
 *     string image_url
 *     string image_alt
 *     string cause
 *     string country
 *     bool   is_featured
 *     string cta_label
 *   }
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;
?>
<article class="dfwc-card<?php echo $data['is_featured'] ? ' dfwc-card--featured' : ''; ?>" role="listitem">
	<a href="<?php echo esc_url( $data['permalink'] ); ?>" class="dfwc-card__link">
		<?php if ( '' !== $data['image_url'] ) : ?>
			<div class="dfwc-card__image-wrap">
				<img
					src="<?php echo esc_url( $data['image_url'] ); ?>"
					alt="<?php echo esc_attr( $data['image_alt'] ); ?>"
					class="dfwc-card__image"
					loading="lazy"
					decoding="async"
				>
				<?php if ( $data['is_featured'] ) : ?>
					<span class="dfwc-card__featured-badge"><?php esc_html_e( 'Featured', 'dfwc-companion' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="dfwc-card__body">
			<h3 class="dfwc-card__title"><?php echo esc_html( $data['title'] ); ?></h3>

			<?php if ( '' !== $data['excerpt'] ) : ?>
				<p class="dfwc-card__excerpt"><?php echo esc_html( $data['excerpt'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $data['cause'] || '' !== $data['country'] ) : ?>
				<div class="dfwc-card__meta">
					<?php if ( '' !== $data['country'] ) : ?>
						<span class="dfwc-card__country"><?php echo esc_html( $data['country'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $data['cause'] ) : ?>
						<span class="dfwc-card__cause"><?php echo esc_html( $data['cause'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<span class="dfwc-card__cta button"><?php echo esc_html( $data['cta_label'] ); ?></span>
		</div>
	</a>
</article>
