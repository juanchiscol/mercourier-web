<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables disponibles:
 * @var string $tienda - Nombre de la tienda/cliente
 */
$tienda = esc_html( $tienda );
$tienda_slug = sanitize_title( $tienda );
?>
<tr class="tienda-header" data-tienda="<?php echo esc_attr( $tienda_slug ); ?>">
	<td colspan="8" style="padding: 0;">
		<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" class="tienda-toggle">
			<strong style="font-size: 16px;">📦 <?php echo $tienda; ?></strong>
			<span class="tienda-toggle-icon" style="font-size: 18px; transition: transform 0.3s;">▼</span>
		</div>
	</td>
</tr>